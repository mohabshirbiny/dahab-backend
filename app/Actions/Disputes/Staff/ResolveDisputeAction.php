<?php

namespace App\Actions\Disputes\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Disputes\GiveBackFrozenTimeAction;
use App\Actions\Disputes\PayCompensationAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Actions\Orders\OpenSellerReturnAction;
use App\Actions\Orders\ReleaseOrderDepositAction;
use App\Actions\Orders\SuspendSellerAction;
use App\Enums\AuditEvent;
use App\Enums\CompensationReason;
use App\Enums\DisputeChangeKind;
use App\Enums\DisputeOutcome;
use App\Enums\DisputeState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\StaffPermission;
use App\Enums\SuspendedReason;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\Dispute;
use App\Models\DisputeChange;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\Staff;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a dispute — always with a reply to its raiser (spec 014 US2,
 * FR-010–FR-016; Part 2 §10). One transaction, listing → order → dispute
 * locked (research R12):
 *  - resume: back to the state it was frozen from, the running deadline given
 *    back the frozen time (extension rows naming the dispute);
 *  - against the sale (only before payment, `order.refund`): cancelled at
 *    inspection, the deposit refunded in full, the piece returned to the seller
 *    — the inspection-cancel path; optionally the seller suspended
 *    (`customer.suspend`);
 *  - optionally compensation to either party (`compensation.pay`, capped).
 * Every money code is checked before anything is written; a refusal leaves
 * the dispute open. The raiser gets the reply; the other party only that the
 * order moves again or is cancelled.
 */
final class ResolveDisputeAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly GiveBackFrozenTimeAction $giveBack,
        private readonly PayCompensationAction $compensate,
        private readonly ReleaseOrderDepositAction $release,
        private readonly OpenSellerReturnAction $returns,
        private readonly SuspendSellerAction $suspend,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{party: string, amount: string, reason: CompensationReason, note: string}|null  $compensation
     * @param  array{reason: SuspendedReason, note: ?string}|null  $suspendSeller
     */
    public function handle(Staff $actor, string $disputeId, DisputeOutcome $outcome, string $reply,
        ?array $compensation = null, ?array $suspendSeller = null, ?RequestContext $ctx = null): Dispute
    {
        $reply = trim($reply);

        if ($outcome === DisputeOutcome::AGAINST_SALE) {
            $this->requireCode($actor, StaffPermission::ORDER_REFUND, $ctx);
        }
        if ($compensation !== null) {
            $this->requireCode($actor, StaffPermission::COMPENSATION_PAY, $ctx);
        }
        if ($suspendSeller !== null) {
            $this->requireCode($actor, StaffPermission::CUSTOMER_SUSPEND, $ctx);
        }

        return DB::transaction(function () use ($actor, $disputeId, $outcome, $reply, $compensation, $suspendSeller, $ctx) {
            $orderId = Dispute::query()->whereKey($disputeId)->firstOrFail(['order_id'])->order_id;
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $dispute = Dispute::query()->whereKey($disputeId)->lockForUpdate()->firstOrFail();

            if ($dispute->state === DisputeState::RESOLVED) {
                throw DomainApiException::illegalDisputeTransition();
            }
            if ($order->state !== OrderState::DISPUTED) {
                throw DomainApiException::illegalOrderTransition();
            }
            if ($outcome === DisputeOutcome::AGAINST_SALE && $dispute->frozen_from === OrderState::READY_TO_COLLECT) {
                throw DomainApiException::disputeOutcomeNotAllowed();
            }

            $releaseTxn = null;
            $refunded = null;
            $returnOpened = null;
            $push = null;

            if ($outcome === DisputeOutcome::RESUME) {
                [$set, $push] = $this->giveBack->compute($order, $dispute);
                $this->moveOrder($order, $dispute->frozen_from, null, $actor, OrderStateChange::NOTE_DISPUTE_RESUMED, $set);
                // A window that had already passed when the order froze is still past after the
                // give-back (old + frozen < now): an `uncollected_expired` piece stays so.
                if ($push !== null) {
                    $this->giveBack->record($order, $dispute, $actor, $push);
                }
            } else {
                $this->moveOrder($order, OrderState::CANCELLED_INSPECTION, null, $actor, OrderStateChange::NOTE_DISPUTE_AGAINST_SALE);
                $refunded = $this->release->handle($order, null, $actor->staff_id);
                $releaseTxn = $order->release_txn_id;
                $returnOpened = $this->returns->handle($order, $listing, null, null, $actor, ListingStateChange::NOTE_INSPECTION_CANCELLED);
                if ($suspendSeller !== null) {
                    $this->suspend->handle($order->seller_id, $suspendSeller['reason'],
                        filled($suspendSeller['note'] ?? null) ? $suspendSeller['note'] : "Dispute {$dispute->dispute_ref} resolved against the sale.", $actor);
                }
            }

            $paid = null;
            if ($compensation !== null) {
                $paid = $this->compensate->handle($actor, $dispute, $order, $compensation['party'], $compensation['amount'],
                    $compensation['reason'], $compensation['note'], $ctx);
            }

            $before = ['state' => $dispute->state->value, 'assigned_to' => $dispute->assigned_to];
            $dispute->forceFill([
                'state' => DisputeState::RESOLVED,
                'outcome' => $outcome,
                'resolution_reply' => $reply,
                'resolved_by' => $actor->staff_id,
                'resolved_at' => CarbonImmutable::now(),
                'release_txn_id' => $releaseTxn,
            ])->save();
            DisputeChange::query()->create([
                'dispute_id' => $dispute->dispute_id,
                'kind' => DisputeChangeKind::RESOLVED,
                'actor_staff_id' => $actor->staff_id,
            ]);

            $this->audit->execute(
                AuditEvent::DISPUTE_RESOLVED,
                'success',
                ['dispute_ref' => $dispute->dispute_ref, 'order_ref' => $order->order_ref, 'outcome' => $outcome->value,
                    'order_state' => $order->state->value, 'deadline_pushed' => $push === null ? null : [
                        'which' => $push['kind']->value, 'from' => $push['old']->toIso8601String(), 'to' => $push['new']->toIso8601String()],
                    'refunded' => $refunded, 'compensation' => $paid === null ? null : ['party' => $paid->party, 'amount' => (string) $paid->amount],
                    'seller_suspended' => $suspendSeller !== null],
                'dispute',
                $dispute->dispute_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: $before,
                reason: $reply,
            );

            $other = $dispute->raised_by === $order->buyer_id ? $order->seller_id : $order->buyer_id;
            $this->tellOrder($dispute->raised_by, OrderEvent::DISPUTE_RESOLVED, $order, $listing, message: $reply);
            if ($outcome === DisputeOutcome::RESUME) {
                $this->tellOrder($other, OrderEvent::DISPUTE_RESUMED, $order, $listing);
            } else {
                $this->tellOrder($other, OrderEvent::DISPUTE_CANCELLED, $order, $listing,
                    amount: $other === $order->buyer_id ? $refunded : null);
                if ($dispute->raised_by === $order->buyer_id) {
                    $this->tellOrder($order->buyer_id, OrderEvent::DISPUTE_CANCELLED, $order, $listing, amount: $refunded);
                }
                $this->tellOrder($order->seller_id, OrderEvent::RETURN_WAITING, $order, $listing,
                    deadline: $returnOpened['return']->return_deadline, code: $returnOpened['code']);
            }
            if ($paid !== null) {
                $this->tellOrder($paid->customer_id, OrderEvent::COMPENSATION_PAID, $order, $listing, amount: (string) $paid->amount);
            }
            $this->flushOrderOutbox();

            return $dispute;
        });
    }

    /** A money or suspension field needs its own code on top of `dispute.handle` (research R7); a denial is audited. */
    private function requireCode(Staff $actor, StaffPermission $code, ?RequestContext $ctx): void
    {
        if ($actor->can($code->value)) {
            return;
        }

        $this->audit->execute(AuditEvent::STAFF_PERMISSION_DENIED, 'denied',
            ['permission' => $code->value, 'action' => 'dispute.resolve'], 'permission', null, $ctx, actorStaffId: $actor->staff_id);

        throw AuthApiException::permissionDenied();
    }
}
