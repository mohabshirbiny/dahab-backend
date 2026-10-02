<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\Staff;
use App\Support\Orders\StaffBranchScope;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The piece reached the branch (spec 012 US1, FR-003, FR-004; Part 3 §5.3).
 * Staff holding `order.receive`, allowed at the order's branch, mark it
 * received: `awaiting_delivery → at_inspection` and the listing `accepted →
 * at_inspection`, in one transaction in the staff scope, listing locked
 * first; one audit row; both parties told after commit.
 */
final class ReceivePieceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly StaffBranchScope $branches,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $orderId, ?RequestContext $ctx = null): Order
    {
        $this->branches->guard($actor, $orderId, $ctx);

        return DB::transaction(function () use ($actor, $orderId, $ctx) {
            $listingId = Order::query()->findOrFail($orderId, ['order_id', 'listing_id'])->listing_id;
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);

            $this->branches->assertCanActAt($actor, $order->branch_id);

            if ($order->state !== OrderState::AWAITING_DELIVERY || $listing->state !== ListingState::ACCEPTED) {
                throw DomainApiException::illegalOrderTransition();
            }

            $this->moveOrder($order, OrderState::AT_INSPECTION, null, $actor);
            $listing = $this->moveListing($listing, ListingState::AT_INSPECTION, null, $actor, ListingStateChange::NOTE_PIECE_RECEIVED);

            $this->audit->execute(
                AuditEvent::ORDER_RECEIVED,
                'success',
                ['order_ref' => $order->order_ref, 'state' => OrderState::AT_INSPECTION->value, 'branch_id' => $order->branch_id],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => OrderState::AWAITING_DELIVERY->value],
            );

            $this->tellOrder($order->buyer_id, OrderEvent::RECEIVED, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::RECEIVED, $order, $listing);
            $this->flushOrderOutbox();

            return $order;
        });
    }
}
