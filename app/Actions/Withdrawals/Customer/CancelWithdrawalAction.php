<?php

namespace App\Actions\Withdrawals\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Withdrawal;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use Illuminate\Support\Facades\DB;

/**
 * The customer stops their own withdrawal before a person releases it (spec
 * 013 US5, FR-010; Part 2 §8): the money goes back from held to available in
 * one balanced entry. A held withdrawal may be cancelled; its hold record
 * stays (analysis A1). A `removing` account with nothing else open is removed.
 */
final class CancelWithdrawalAction
{
    use WorksWithdrawals;

    public function __construct(
        private readonly WithdrawalLedger $ledger,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Customer $customer, string $withdrawalId, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($customer, $withdrawalId, $ctx) {
            [$withdrawal, $accounts] = $this->lockWithdrawal($withdrawalId);
            abort_unless($withdrawal->customer_id === $customer->customer_id, 404);

            $from = $withdrawal->state;
            if (! $from->canMoveTo(WithdrawalState::CANCELLED)) {
                throw DomainApiException::illegalWithdrawalTransition();
            }

            $txn = $this->ledger->returnToAvailable($withdrawal, $customer->customer_id, null, 'cancelled by the customer');
            $this->moveWithdrawal($withdrawal, WithdrawalState::CANCELLED, ['return_txn_id' => $txn->ledger_txn_id]);
            $this->finishRemoval($accounts, $withdrawal->payout_account_id, $customer->customer_id, null);

            $this->audit->execute(AuditEvent::WITHDRAWAL_CANCELLED, 'success',
                ['number' => $withdrawal->number(), 'amount' => $withdrawal->amount, 'return_txn_id' => $txn->ledger_txn_id],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorCustomerId: $customer->customer_id,
                before: ['state' => $from->value]);

            $this->flushPayoutOutbox();

            return $withdrawal->setRelation('account', $accounts->get($withdrawal->payout_account_id));
        });
    }
}
