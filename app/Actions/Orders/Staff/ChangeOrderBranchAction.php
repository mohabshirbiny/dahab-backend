<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\DeadlineKind;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderBranchChange;
use App\Models\OrderDeadlineExtension;
use App\Models\Staff;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff move an open order to another branch the seller named (spec 012 US3,
 * FR-008, research R17; Part 3 §5.2). The reach-branch clock keeps running
 * (locked decision) unless staff also set a later deadline, which is then a
 * deadline extension too. Only while the piece has not reached a branch.
 * Audited with the reason; both parties told.
 */
final class ChangeOrderBranchAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $orderId, int $branchId, string $reason, ?CarbonImmutable $extendTo, ?RequestContext $ctx = null): Order
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $orderId, $branchId, $reason, $extendTo, $ctx) {
            $listingId = Order::query()->findOrFail($orderId, ['order_id', 'listing_id'])->listing_id;
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);

            if ($order->state !== OrderState::AWAITING_DELIVERY) {
                throw DomainApiException::orderNotOpen();
            }
            if ($order->branch_id === $branchId) {
                throw ValidationException::withMessages(['branch_id' => ['The order is already at this branch.']]);
            }
            $branch = $listing->branches()->where('branch.branch_id', $branchId)->where('branch.is_enabled', true)->first();
            if (! $branch instanceof Branch) {
                throw DomainApiException::branchNotInOptions();
            }

            $old = $order->reach_branch_deadline;
            if ($extendTo !== null && (! $extendTo->greaterThan($old) || ! $extendTo->isFuture())) {
                throw DomainApiException::deadlineMustMoveForward();
            }

            $from = $order->branch_id;
            $order->forceFill(['branch_id' => $branchId] + ($extendTo !== null
                ? ['reach_branch_deadline' => $extendTo, 'reach_reminder_sent_at' => null] : []))->save();
            $order->unsetRelation('branch');

            OrderBranchChange::query()->create([
                'order_id' => $order->order_id,
                'from_branch' => $from,
                'to_branch' => $branchId,
                'changed_by' => $actor->staff_id,
                'extended_to' => $extendTo,
                'reason' => $reason,
            ]);
            if ($extendTo !== null) {
                OrderDeadlineExtension::query()->create([
                    'order_id' => $order->order_id,
                    'which' => DeadlineKind::REACH_BRANCH,
                    'old_deadline' => $old,
                    'new_deadline' => $extendTo,
                    'granted_by' => $actor->staff_id,
                    'reason' => $reason,
                ]);
            }

            $this->audit->execute(
                AuditEvent::ORDER_BRANCH_CHANGED,
                'success',
                ['order_ref' => $order->order_ref, 'branch_id' => $branchId, 'reach_branch_deadline' => $order->reach_branch_deadline->toIso8601String()],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['branch_id' => $from, 'reach_branch_deadline' => $old->toIso8601String()],
                reason: $reason,
            );

            $this->tellOrder($order->seller_id, OrderEvent::BRANCH_CHANGED, $order, $listing, deadline: $order->reach_branch_deadline);
            $this->tellOrder($order->buyer_id, OrderEvent::BRANCH_CHANGED, $order, $listing, deadline: $order->reach_branch_deadline);
            $this->flushOrderOutbox();

            return $order;
        });
    }
}
