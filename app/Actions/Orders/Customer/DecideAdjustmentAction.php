<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Actions\Orders\OpenSellerReturnAction;
use App\Actions\Orders\ReleaseOrderDepositAction;
use App\Enums\AuditEvent;
use App\Enums\InspectionOutcome;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\SettlementDecision;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Orders\OrderSettlement;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The buyer answers an adjusted price (spec 012 US5, FR-013, research R15;
 * Part 2 §6): accept → awaiting the balance with its deadline; decline →
 * `cancelled_inspection`, the deposit back in full, the seller not suspended,
 * the piece returned to the seller with no compensation. A regrade can be
 * answered only once staff have priced it. The buyer's path runs in the
 * audited `order` scope; the sweep declines an adjustment left unanswered
 * for `deadline.buyer_pay_days`, as the system actor, without a decision row
 * (the row means the buyer decided).
 */
final class DecideAdjustmentAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly OrderSettlement $settlement,
        private readonly DeadlinePolicy $deadlines,
        private readonly ReleaseOrderDepositAction $release,
        private readonly OpenSellerReturnAction $returns,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function byBuyer(Customer $buyer, string $orderId, bool $accept, string $inspectionId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->buyer_id !== $buyer->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(
            fn () => $this->decide($order->listing_id, $orderId, $accept, $inspectionId, $buyer, null, $ctx),
        ));
    }

    /** The unanswered-adjustment pass of the sweep. Null when it is no longer due. */
    public function byDeadline(Staff $system, string $orderId): ?Order
    {
        return DB::transaction(function () use ($system, $orderId) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            if ($listingId === null) {
                return null;
            }
            $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            if ($order->state !== OrderState::WEIGHT_ADJUST_PENDING || $order->decision_due_deadline === null
                || $order->decision_due_deadline->isFuture()) {
                return null;
            }

            return $this->decide($listingId, $orderId, false, null, null, $system, null);
        });
    }

    private function decide(string $listingId, string $orderId, bool $accept, ?string $inspectionId, ?Customer $buyer, ?Staff $system, ?RequestContext $ctx): Order
    {
        $listing = $this->lockListing($listingId);
        $order = $this->lockOrder($orderId);
        $this->assertNotFrozen($order);

        if ($order->state !== OrderState::WEIGHT_ADJUST_PENDING) {
            throw DomainApiException::illegalOrderTransition();
        }

        $result = $order->latestInspection();
        if ($result === null || ! $result->outcome->needsDecision()) {
            throw DomainApiException::illegalOrderTransition();
        }
        if ($inspectionId !== null && $result->inspection_id !== $inspectionId) {
            throw DomainApiException::inspectionCorrectionNotAllowed();
        }
        if ($result->outcome === InspectionOutcome::STONE_REGRADE && $order->proposed_price === null) {
            throw DomainApiException::priceNotSet();
        }

        $request = BuyRequest::query()->whereKey($order->buy_request_id)->firstOrFail();
        $newPrice = (string) $this->settlement->newPrice($order, $listing, $request, $result);
        $oldPrice = (string) $order->locked_total_price;

        if ($buyer !== null) {
            SettlementDecision::query()->create([
                'order_id' => $order->order_id,
                'inspection_id' => $result->inspection_id,
                'buyer_accepted' => $accept,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ]);
        }

        if ($accept) {
            $this->moveOrder($order, OrderState::AWAITING_BALANCE, $buyer, $system, null, [
                'balance_due_deadline' => $this->deadlines->payWindow(),
                'decision_due_deadline' => null,
            ]);
            $this->tellOrder($order->seller_id, OrderEvent::DECISION_ACCEPTED, $order, $listing);
        } else {
            $this->moveOrder($order, OrderState::CANCELLED_INSPECTION, $buyer, $system,
                $buyer === null ? OrderStateChange::NOTE_NO_ANSWER : null, ['decision_due_deadline' => null]);
            $refunded = $this->release->handle($order, $buyer?->customer_id, $system?->staff_id);
            $opened = $listing->state === ListingState::SETTLING
                ? $this->returns->handle($order, $listing, null, $buyer, $system, ListingStateChange::NOTE_ADJUSTMENT_DECLINED)
                : null;

            $this->tellOrder($order->seller_id, OrderEvent::DECISION_DECLINED, $order, $listing);
            if ($opened !== null) {
                $this->tellOrder($order->seller_id, OrderEvent::RETURN_WAITING, $order, $listing,
                    deadline: $opened['return']->return_deadline, code: $opened['code']);
            }
            if ($buyer === null) {
                // The buyer did not decide: tell them their deposit came back.
                $this->tellOrder($order->buyer_id, OrderEvent::DECISION_EXPIRED, $order, $listing, amount: $refunded);
            }
        }

        $this->audit->execute(
            AuditEvent::ORDER_DECIDED,
            'success',
            ['order_ref' => $order->order_ref, 'accepted' => $accept, 'by_sweep' => $buyer === null,
                'old_price' => $oldPrice, 'new_price' => $newPrice, 'state' => $order->state->value],
            'order',
            $order->order_id,
            $ctx,
            actorCustomerId: $buyer?->customer_id,
            actorStaffId: $system?->staff_id,
            before: ['state' => OrderState::WEIGHT_ADJUST_PENDING->value],
        );

        $this->flushOrderOutbox();

        return $order;
    }
}
