<?php

namespace App\Actions\Orders;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Enums\AuditEvent;
use App\Enums\BuyRequestEvent;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Staff;
use App\Support\BuyRequests\DepositLedger;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Staff cancel an acceptance (spec 011 FR-020a, research R22; Part 1 §4.1
 * "Cancel an order"). Until the orders module exists this is the only exit for
 * a deposit held on an accepted order. One transaction in the staff scope,
 * listing locked first: the order `awaiting_delivery → cancelled_staff` with
 * who, when and why; the buyer's deposit released in full; the listing
 * `accepted → live` (back on the market, empty line) or `accepted → withdrawn`
 * (final), with the reason in its history; one audit row. Buyer and seller are
 * told after commit. Not a seller cancellation: nothing counts toward
 * suspending the seller. The request stays `accepted` — it was.
 */
final class CancelAcceptanceAction
{
    use MovesOrder, ReleasesRequests;

    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly DepositLedger $deposits,
    ) {}

    /** @return array{order: Order, listing: Listing, refunded: string} */
    public function handle(Staff $actor, string $orderId, string $reason, bool $relist, ?RequestContext $ctx = null): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $orderId, $reason, $relist, $ctx) {
            $listingId = Order::query()->findOrFail($orderId, ['order_id', 'listing_id'])->listing_id;

            $listing = $this->lockListing($listingId);
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->assertNotFrozen($order);

            if ($order->state !== OrderState::AWAITING_DELIVERY || $listing->state !== ListingState::ACCEPTED) {
                throw DomainApiException::orderNotCancellable();
            }

            $request = BuyRequest::query()->whereKey($order->buy_request_id)->lockForUpdate()->firstOrFail();

            $order = $this->moveOrder($order, OrderState::CANCELLED_STAFF, null, $actor, $reason, [
                'cancelled_by' => $actor->staff_id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $txn = $this->deposits->release($request, null, $actor->staff_id, $order->order_id);
            $order->forceFill(['release_txn_id' => $txn->ledger_txn_id])->save();

            $to = $relist ? ListingState::LIVE : ListingState::WITHDRAWN;
            $listing = $this->moveListing($listing, $to, null, $actor, $reason);
            if ($relist) {
                $this->freeAgain[] = $listing->listing_id;
            }

            $this->audit->execute(
                AuditEvent::ORDER_CANCELLED,
                'success',
                [
                    'order_ref' => $order->order_ref,
                    'state' => OrderState::CANCELLED_STAFF->value,
                    'listing_id' => $listing->listing_id,
                    'listing_state' => $to->value,
                    'relist' => $relist,
                    'refunded' => (string) $request->deposit_amount,
                    'buyer_id' => $order->buyer_id,
                    'seller_id' => $order->seller_id,
                ],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => OrderState::AWAITING_DELIVERY->value, 'listing_state' => ListingState::ACCEPTED->value],
                reason: $reason,
            );

            $this->tell($order->buyer_id, BuyRequestEvent::ORDER_CANCELLED, $listing,
                amount: (string) $request->deposit_amount, orderRef: $order->order_ref, reason: $reason);
            $this->tell($order->seller_id, BuyRequestEvent::ORDER_CANCELLED, $listing,
                orderRef: $order->order_ref, reason: $reason);
            $this->flushAfterCommit();

            return ['order' => $order->refresh(), 'listing' => $listing->refresh(), 'refunded' => (string) $request->deposit_amount];
        });
    }
}
