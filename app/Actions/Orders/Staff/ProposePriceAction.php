<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\InspectionOutcome;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Order;
use App\Models\Staff;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff set the new price after a stone regrade (spec 012 FR-012,
 * research R11): IGI grades the stone, it never prices it. Allowed once per
 * result, while the order waits for the buyer and no price is set; the
 * buyer's decision clock starts now and both parties are told.
 */
final class ProposePriceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly DeadlinePolicy $deadlines,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $orderId, string $price, string $reason, ?RequestContext $ctx = null): Order
    {
        return DB::transaction(function () use ($actor, $orderId, $price, $reason, $ctx) {
            $listingId = Order::query()->findOrFail($orderId, ['order_id', 'listing_id'])->listing_id;
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $this->assertNotFrozen($order);

            if ($order->state !== OrderState::WEIGHT_ADJUST_PENDING || $order->proposed_price !== null
                || $order->latestInspection()?->outcome !== InspectionOutcome::STONE_REGRADE) {
                throw DomainApiException::illegalOrderTransition();
            }

            $price = Money::round4($price);
            $order->forceFill([
                'proposed_price' => $price,
                'proposed_by' => $actor->staff_id,
                'proposed_at' => CarbonImmutable::now(),
                'decision_due_deadline' => $this->deadlines->payWindow(),
            ])->save();

            $this->audit->execute(
                AuditEvent::ORDER_PRICE_PROPOSED,
                'success',
                ['order_ref' => $order->order_ref, 'proposed_price' => $price, 'locked_total_price' => (string) $order->locked_total_price],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                reason: $reason,
            );

            $this->tellOrder($order->buyer_id, OrderEvent::PRICE_PROPOSED, $order, $listing, amount: $price, deadline: $order->decision_due_deadline);
            $this->tellOrder($order->seller_id, OrderEvent::RESULT_BUYER_DECIDING, $order, $listing);
            $this->flushOrderOutbox();

            return $order;
        });
    }
}
