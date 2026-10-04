<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\DeadlineKind;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderDeadlineExtension;
use App\Models\Staff;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff extend a running deadline on request (spec 012 US3, FR-009,
 * research R17; Part 3 §1.4): the reach-branch deadline (awaiting delivery),
 * the balance deadline (awaiting the balance) or the collection window (ready
 * to collect). Staff set the new instant; it only moves forward. Its reminder
 * may fire again; a collection window extended after it passed puts the
 * piece back to `sold`. Audited with the reason; both parties told.
 */
final class ExtendOrderDeadlineAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  string|null  $extensionRequestId  spec 014: the seller's request this extension answers */
    public function handle(Staff $actor, string $orderId, DeadlineKind $which, CarbonImmutable $newDeadline, string $reason, ?RequestContext $ctx = null, ?string $extensionRequestId = null): Order
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $orderId, $which, $newDeadline, $reason, $ctx, $extensionRequestId) {
            $listingId = Order::query()->findOrFail($orderId, ['order_id', 'listing_id'])->listing_id;
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $this->assertNotFrozen($order);

            [$state, $column, $reminder] = match ($which) {
                DeadlineKind::REACH_BRANCH => [OrderState::AWAITING_DELIVERY, 'reach_branch_deadline', 'reach_reminder_sent_at'],
                DeadlineKind::BALANCE => [OrderState::AWAITING_BALANCE, 'balance_due_deadline', 'balance_reminder_sent_at'],
                DeadlineKind::COLLECT => [OrderState::READY_TO_COLLECT, 'collect_deadline', null],
                default => throw DomainApiException::deadlineNotRunning(),
            };

            $collectOpen = $which !== DeadlineKind::COLLECT || ($order->collection()->whereNull('collected_at')->exists()
                && in_array($listing->state, [ListingState::SOLD, ListingState::UNCOLLECTED_EXPIRED], true));
            $old = $order->{$column};
            if ($order->state !== $state || $old === null || ! $collectOpen) {
                throw DomainApiException::deadlineNotRunning();
            }
            if (! $newDeadline->greaterThan($old) || ! $newDeadline->isFuture()) {
                throw DomainApiException::deadlineMustMoveForward();
            }

            $order->forceFill([$column => $newDeadline] + ($reminder !== null ? [$reminder => null] : []))->save();

            OrderDeadlineExtension::query()->create([
                'order_id' => $order->order_id,
                'which' => $which,
                'old_deadline' => $old,
                'new_deadline' => $newDeadline,
                'granted_by' => $actor->staff_id,
                'reason' => $reason,
                'extension_request_id' => $extensionRequestId,
            ]);

            if ($which === DeadlineKind::COLLECT && $listing->state === ListingState::UNCOLLECTED_EXPIRED) {
                $listing = $this->moveListing($listing, ListingState::SOLD, null, $actor, ListingStateChange::NOTE_COLLECT_DEADLINE_EXTENDED);
            }

            $this->audit->execute(
                AuditEvent::ORDER_DEADLINE_EXTENDED,
                'success',
                ['order_ref' => $order->order_ref, 'which' => $which->value, 'new_deadline' => $newDeadline->toIso8601String()],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['which' => $which->value, 'deadline' => $old->toIso8601String()],
                reason: $reason,
            );

            $this->tellOrder($order->seller_id, OrderEvent::DEADLINE_EXTENDED, $order, $listing, deadline: $newDeadline);
            $this->tellOrder($order->buyer_id, OrderEvent::DEADLINE_EXTENDED, $order, $listing, deadline: $newDeadline);
            $this->flushOrderOutbox();

            return $order;
        });
    }
}
