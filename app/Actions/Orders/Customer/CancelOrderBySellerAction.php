<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Actions\Orders\ReleaseOrderDepositAction;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\SellerCancellation;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The seller cannot deliver (spec 012 US2, FR-005, FR-006, research R8):
 * either they cancel an order awaiting delivery, or the reach-branch deadline
 * passes and the sweep does it as the system actor. One transaction, listing
 * locked first: `awaiting_delivery → cancelled_seller`, the buyer's deposit
 * released in full, a seller-cancellation counted, the listing withdrawn (the
 * seller still has the piece). The seller's path runs in the audited `order`
 * scope (analysis C1). It never suspends: the sweep's suspension pass applies
 * the threshold in its own operation (analysis C2).
 */
final class CancelOrderBySellerAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly ReleaseOrderDepositAction $release,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** The seller's own cancel (verified gate). 404 when it is not their order. */
    public function bySeller(Customer $seller, string $orderId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->seller_id !== $seller->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(
            fn () => $this->cancel($this->lockListing($order->listing_id), $this->lockOrder($orderId), $seller, null, $ctx),
        ));
    }

    /**
     * The reach-branch sweep (FR-006): inside the caller's elevated scope, as the
     * system actor. Returns null when the order is no longer due (received,
     * extended or already cancelled while the sweep was choosing it).
     */
    public function byDeadline(Staff $system, string $orderId): ?Order
    {
        return DB::transaction(function () use ($system, $orderId) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            if ($listingId === null) {
                return null;
            }

            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            if ($order->state !== OrderState::AWAITING_DELIVERY || $order->reach_branch_deadline->isFuture()) {
                return null;
            }

            return $this->cancel($listing, $order, null, $system, null);
        });
    }

    /** The listing and the order arrive locked, in that order (research R4). */
    private function cancel(Listing $listing, Order $order, ?Customer $seller, ?Staff $system, ?RequestContext $ctx): Order
    {
        if ($order->state !== OrderState::AWAITING_DELIVERY || $listing->state !== ListingState::ACCEPTED) {
            throw DomainApiException::illegalOrderTransition();
        }

        $bySweep = $seller === null;
        $this->moveOrder($order, OrderState::CANCELLED_SELLER, $seller, $system, $bySweep ? OrderStateChange::NOTE_DEADLINE_MISSED : null);
        $refunded = $this->release->handle($order, $seller?->customer_id, $system?->staff_id);

        SellerCancellation::query()->create([
            'order_id' => $order->order_id,
            'seller_id' => $order->seller_id,
            'by_sweep' => $bySweep,
        ]);

        $listing = $this->moveListing($listing, ListingState::WITHDRAWN, $seller, $system,
            $bySweep ? ListingStateChange::NOTE_DEADLINE_MISSED : ListingStateChange::NOTE_SELLER_CANCELLED);

        $this->audit->execute(
            AuditEvent::ORDER_SELLER_CANCELLED,
            'success',
            ['order_ref' => $order->order_ref, 'state' => OrderState::CANCELLED_SELLER->value, 'by_sweep' => $bySweep,
                'refunded' => $refunded, 'buyer_id' => $order->buyer_id, 'seller_id' => $order->seller_id],
            'order',
            $order->order_id,
            $ctx,
            actorCustomerId: $seller?->customer_id,
            actorStaffId: $system?->staff_id,
            before: ['state' => OrderState::AWAITING_DELIVERY->value],
        );

        $this->tellOrder($order->buyer_id, $bySweep ? OrderEvent::DEADLINE_MISSED : OrderEvent::SELLER_CANCELLED, $order, $listing, amount: $refunded);
        if ($bySweep) {
            $this->tellOrder($order->seller_id, OrderEvent::DEADLINE_MISSED, $order, $listing);
        }
        $this->flushOrderOutbox();

        return $order->refresh();
    }
}
