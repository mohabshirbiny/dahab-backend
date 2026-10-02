<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Exceptions\DomainApiException;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Models\Staff;
use App\Support\Orders\CollectionCodes;
use App\Support\Orders\StaffBranchScope;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff hand a returned piece back to its seller against the seller's code
 * (spec 012 FR-019, research R10, R13): the listing becomes `withdrawn`
 * (from `awaiting_seller_return`, or `seller_unclaimed` after the window),
 * the return is collected, no money moves. A wrong code is counted and
 * committed before the refusal, and audited; five lock the handover for
 * fifteen minutes.
 */
final class HandoverReturnedPieceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly StaffBranchScope $branches,
        private readonly CollectionCodes $codes,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $orderId, string $code, ?RequestContext $ctx = null): Order
    {
        $this->branches->guard($actor, $orderId, $ctx);

        $outcome = DB::transaction(function () use ($actor, $orderId, $code, $ctx) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $this->branches->assertCanActAt($actor, $order->branch_id);
            $return = SellerReturn::query()->where('order_id', $orderId)->lockForUpdate()->first();

            if ($return === null || ! $return->isOpen()
                || ! in_array($listing->state, [ListingState::AWAITING_SELLER_RETURN, ListingState::SELLER_UNCLAIMED], true)) {
                throw DomainApiException::illegalListingTransition();
            }

            $this->codes->assertNotLocked($return);
            if (! $this->codes->matches($code, $return->code_hash)) {
                return ['ok' => false, 'left' => $this->codes->registerFailure($return), 'order' => $order];
            }

            $return->forceFill([
                'collected_at' => CarbonImmutable::now(),
                'handover_by' => $actor->staff_id,
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();
            $listing = $this->moveListing($listing, ListingState::WITHDRAWN, null, $actor, ListingStateChange::NOTE_RETURN_COLLECTED);

            $this->audit->execute(
                AuditEvent::ORDER_RETURN_HANDED_OVER,
                'success',
                ['order_ref' => $order->order_ref, 'listing_state' => ListingState::WITHDRAWN->value, 'branch_id' => $order->branch_id],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
            );

            $this->tellOrder($order->seller_id, OrderEvent::COLLECTED, $order, $listing);
            $this->flushOrderOutbox();

            return ['ok' => true, 'order' => $order];
        });

        if (! $outcome['ok']) {
            $this->audit->execute(
                AuditEvent::ORDER_HANDOVER_FAILED,
                'denied',
                ['order_ref' => $outcome['order']->order_ref, 'who' => 'seller', 'attempts_left' => $outcome['left']],
                'order',
                $orderId,
                $ctx,
                actorStaffId: $actor->staff_id,
            );

            throw $outcome['left'] === 0
                ? DomainApiException::handoverLocked((int) config('dahab-orders.handover_lock_minutes', 15) * 60)
                : DomainApiException::invalidCollectionCode($outcome['left']);
        }

        return $outcome['order'];
    }
}
