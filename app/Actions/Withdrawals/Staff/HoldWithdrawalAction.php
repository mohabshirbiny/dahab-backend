<?php

namespace App\Actions\Withdrawals\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutEvent;
use App\Enums\WithdrawalHoldReason;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Hold / unhold (spec 013 US6, the design's *Hold*): a flag on a withdrawal
 * under review — a reason from the list, a message the customer is told, a
 * staff note. A held withdrawal is never released. Unhold clears the flag (the
 * audit log keeps the hold). No state change, no money.
 */
final class HoldWithdrawalAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function hold(Staff $actor, string $withdrawalId, WithdrawalHoldReason $reason, string $message, string $note, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($actor, $withdrawalId, $reason, $message, $note, $ctx) {
            [$withdrawal] = $this->lockWithdrawal($withdrawalId);

            if ($withdrawal->state !== WithdrawalState::UNDER_REVIEW || $withdrawal->held_at !== null) {
                throw DomainApiException::illegalWithdrawalTransition();
            }

            $withdrawal->forceFill([
                'held_at' => now(),
                'held_by' => $actor->staff_id,
                'hold_reason' => $reason,
                'hold_message' => $message,
                'hold_note' => $note,
            ])->save();

            $this->audit->execute(AuditEvent::WITHDRAWAL_HELD, 'success',
                ['number' => $withdrawal->number(), 'hold_reason' => $reason->value, 'hold_message' => $message],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorStaffId: $actor->staff_id, reason: $note);

            $this->tellPayout($withdrawal->customer_id, new PayoutNotification(PayoutEvent::WITHDRAWAL_HELD,
                amount: (string) $withdrawal->amount, number: $withdrawal->number(), message: $message));
            $this->flushPayoutOutbox();

            return $withdrawal;
        });
    }

    public function unhold(Staff $actor, string $withdrawalId, ?string $note, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($actor, $withdrawalId, $note, $ctx) {
            [$withdrawal] = $this->lockWithdrawal($withdrawalId);

            if (! $withdrawal->isOnHold()) {
                throw DomainApiException::illegalWithdrawalTransition();
            }

            $before = ['hold_reason' => $withdrawal->hold_reason?->value, 'held_by' => $withdrawal->held_by];
            $withdrawal->forceFill(['held_at' => null, 'held_by' => null, 'hold_reason' => null, 'hold_message' => null, 'hold_note' => null])->save();

            $this->audit->execute(AuditEvent::WITHDRAWAL_UNHELD, 'success',
                ['number' => $withdrawal->number()],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorStaffId: $actor->staff_id, before: $before, reason: $note);

            return $withdrawal;
        });
    }
}
