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
use App\Models\OrderCollection;
use App\Models\Staff;
use App\Support\Orders\CollectionCodes;
use App\Support\Orders\StaffBranchScope;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff hand a paid piece to its buyer against the buyer's code (spec 012
 * US8, FR-020, FR-021, research R10, R17; Part 2 §7): `ready_to_collect →
 * completed`, the collection stamped, no money (settlement happened at
 * payment). A piece past its collection window (`uncollected_expired`) goes
 * back to `sold` first. A wrong code is counted and committed before the
 * refusal, and audited; five lock the handover for fifteen minutes.
 * Spec 014: when the buyer named someone else and that person collects
 * (`collector = proxy`), staff must confirm they checked the person's ID
 * against the named proxy first (`proxy_details_missing`, no attempt counted);
 * the buyer may still collect in person.
 */
final class HandoverPieceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly StaffBranchScope $branches,
        private readonly CollectionCodes $codes,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $orderId, string $code, ?RequestContext $ctx = null, bool $byProxy = false, bool $proxyIdChecked = false): Order
    {
        $this->branches->guard($actor, $orderId, $ctx);

        $outcome = DB::transaction(function () use ($actor, $orderId, $code, $ctx, $byProxy, $proxyIdChecked) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $this->assertNotFrozen($order);
            $this->branches->assertCanActAt($actor, $order->branch_id);
            $collection = OrderCollection::query()->where('order_id', $orderId)->lockForUpdate()->first();

            if ($order->state !== OrderState::READY_TO_COLLECT || $collection === null || $collection->collected_at !== null
                || ! in_array($listing->state, [ListingState::SOLD, ListingState::UNCOLLECTED_EXPIRED], true)) {
                throw DomainApiException::illegalOrderTransition();
            }

            if ($byProxy && (! $collection->is_proxy || ! $proxyIdChecked)) {
                throw DomainApiException::proxyDetailsMissing();
            }

            $this->codes->assertNotLocked($collection);
            if (! $this->codes->matches($code, $collection->code_hash)) {
                return ['ok' => false, 'left' => $this->codes->registerFailure($collection), 'order' => $order];
            }

            $now = CarbonImmutable::now();
            $collection->forceFill([
                'collected_at' => $now,
                'handover_by' => $actor->staff_id,
                'failed_attempts' => 0,
                'locked_until' => null,
            ] + ($byProxy ? ['collected_by_proxy' => true, 'proxy_id_checked_by' => $actor->staff_id] : []))->save();

            $late = $listing->state === ListingState::UNCOLLECTED_EXPIRED;
            if ($late) {
                $listing = $this->moveListing($listing, ListingState::SOLD, null, $actor, ListingStateChange::NOTE_COLLECTED);
            }
            $this->moveOrder($order, OrderState::COMPLETED, null, $actor, null, ['completed_at' => $now]);

            $this->audit->execute(
                AuditEvent::ORDER_HANDED_OVER,
                'success',
                ['order_ref' => $order->order_ref, 'state' => OrderState::COMPLETED->value, 'after_window' => $late, 'branch_id' => $order->branch_id,
                    'collector' => $byProxy ? 'proxy' : 'buyer', 'proxy_name' => $byProxy ? $collection->proxy_name : null],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => OrderState::READY_TO_COLLECT->value],
            );

            $this->tellOrder($order->buyer_id, OrderEvent::COLLECTED, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::COLLECTED, $order, $listing);
            $this->flushOrderOutbox();

            return ['ok' => true, 'order' => $order];
        });

        if (! $outcome['ok']) {
            $this->audit->execute(
                AuditEvent::ORDER_HANDOVER_FAILED,
                'denied',
                ['order_ref' => $outcome['order']->order_ref, 'who' => 'buyer', 'attempts_left' => $outcome['left']],
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
