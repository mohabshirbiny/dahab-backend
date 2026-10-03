<?php

namespace App\Actions\Withdrawals\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutEvent;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use Illuminate\Support\Facades\DB;

/**
 * Release (Part 2 §9; spec 013 US6, FR-013): a person has sent the transfer
 * at Dahab's bank and records it here. One transaction: lock (R6 order),
 * check `under_review`, not held, and the account the customer's and `active`
 * or `removing` (analysis I1); post held → bank; record the bank details,
 * the reviewer and the release txn; remove a `removing` account whose last
 * open withdrawal this was; audit. The pause is not re-checked: every open
 * withdrawal was cancelled when the account in use changed (R6).
 */
final class ReleaseWithdrawalAction
{
    use WorksWithdrawals;

    public function __construct(
        private readonly WithdrawalLedger $ledger,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $withdrawalId, string $bankTxnNumber, ?string $transferReference, ?string $valueDate, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($actor, $withdrawalId, $bankTxnNumber, $transferReference, $valueDate, $ctx) {
            [$withdrawal, $accounts] = $this->lockWithdrawal($withdrawalId);

            if ($withdrawal->state !== WithdrawalState::UNDER_REVIEW) {
                throw DomainApiException::illegalWithdrawalTransition();
            }
            if ($withdrawal->isOnHold()) {
                throw DomainApiException::withdrawalOnHold();
            }

            $account = $accounts->get($withdrawal->payout_account_id);
            if ($account === null || ! $account->state->canReceiveRelease()) {
                throw DomainApiException::payoutAccountNotActive();
            }

            $txn = $this->ledger->release($withdrawal, $actor->staff_id);
            $this->moveWithdrawal($withdrawal, WithdrawalState::RELEASED, [
                'release_txn_id' => $txn->ledger_txn_id,
                'released_at' => now(),
                'reviewed_by' => $actor->staff_id,
                'bank_txn_number' => $bankTxnNumber,
                'transfer_reference' => $transferReference,
                'value_date' => $valueDate,
            ]);
            $this->finishRemoval($accounts, $withdrawal->payout_account_id, null, $actor->staff_id);

            $this->audit->execute(AuditEvent::WITHDRAWAL_RELEASED, 'success',
                ['number' => $withdrawal->number(), 'amount' => $withdrawal->amount, 'account' => $account->shortLabel(),
                    'bank_txn_number' => $bankTxnNumber, 'transfer_reference' => $transferReference, 'value_date' => $valueDate,
                    'release_txn_id' => $txn->ledger_txn_id],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorStaffId: $actor->staff_id,
                before: ['state' => WithdrawalState::UNDER_REVIEW->value]);

            $this->tellPayout($withdrawal->customer_id, new PayoutNotification(PayoutEvent::WITHDRAWAL_RELEASED,
                account: $account->shortLabel(), amount: (string) $withdrawal->amount, number: $withdrawal->number()));
            $this->flushPayoutOutbox();

            return $withdrawal;
        });
    }
}
