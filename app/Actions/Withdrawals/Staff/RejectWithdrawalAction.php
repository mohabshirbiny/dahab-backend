<?php

namespace App\Actions\Withdrawals\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutEvent;
use App\Enums\WithdrawalRejectReason;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use Illuminate\Support\Facades\DB;

/**
 * Reject (Part 2 §9; spec 013 US6): from `requested` or `under_review` (held
 * or not — the hold record stays, analysis A1) to `rejected`; the money goes
 * back from held to available. The customer is told the reason, never the note.
 */
final class RejectWithdrawalAction
{
    use WorksWithdrawals;

    public function __construct(
        private readonly WithdrawalLedger $ledger,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $withdrawalId, WithdrawalRejectReason $reason, string $note, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($actor, $withdrawalId, $reason, $note, $ctx) {
            [$withdrawal, $accounts] = $this->lockWithdrawal($withdrawalId);
            $from = $withdrawal->state;

            if (! $from->canMoveTo(WithdrawalState::REJECTED)) {
                throw DomainApiException::illegalWithdrawalTransition();
            }

            $txn = $this->ledger->returnToAvailable($withdrawal, null, $actor->staff_id, 'rejected');
            $this->moveWithdrawal($withdrawal, WithdrawalState::REJECTED, [
                'return_txn_id' => $txn->ledger_txn_id,
                'reviewed_by' => $actor->staff_id,
                'rejection_reason' => $reason,
                'rejection_note' => $note,
            ]);
            $this->finishRemoval($accounts, $withdrawal->payout_account_id, null, $actor->staff_id);

            $this->audit->execute(AuditEvent::WITHDRAWAL_REJECTED, 'success',
                ['number' => $withdrawal->number(), 'amount' => $withdrawal->amount, 'rejection_reason' => $reason->value, 'return_txn_id' => $txn->ledger_txn_id],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorStaffId: $actor->staff_id,
                before: ['state' => $from->value], reason: $note);

            $this->tellPayout($withdrawal->customer_id, new PayoutNotification(PayoutEvent::WITHDRAWAL_REJECTED,
                amount: (string) $withdrawal->amount, number: $withdrawal->number(), reason: $reason->label(), reasonAr: $reason->labelAr()));
            $this->flushPayoutOutbox();

            return $withdrawal;
        });
    }
}
