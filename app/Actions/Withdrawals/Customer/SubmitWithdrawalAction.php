<?php

namespace App\Actions\Withdrawals\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Withdrawal;
use App\Models\WithdrawalConfirmation;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Withdraw" (spec 013 US4, FR-009; Part 2 §8 `POST /me/withdrawals`). One
 * transaction: lock the customer's accounts, then the confirmation; check the
 * email confirmation (theirs, confirmed, unused, unexpired, same amount and
 * account) and the gates again; post the hold (available → held) through the
 * money service; create the withdrawal `requested`; use the confirmation.
 * No money leaves the bank until a person releases it.
 */
final class SubmitWithdrawalAction
{
    use WorksWithdrawals;

    public function __construct(
        private readonly WithdrawalLedger $ledger,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Customer $customer, string $confirmationId, string $amount, string $accountId, ?RequestContext $ctx = null): Withdrawal
    {
        $amount = Money::fixed4($amount);

        return DB::transaction(function () use ($customer, $confirmationId, $amount, $accountId, $ctx) {
            // Lock order (R6): the customer's accounts first, then the confirmation.
            $accounts = $this->lockAccounts($customer->customer_id);
            $confirmation = WithdrawalConfirmation::query()->whereKey($confirmationId)
                ->where('customer_id', $customer->customer_id)->lockForUpdate()->first();

            if ($confirmation === null
                || $confirmation->state() !== WithdrawalConfirmation::CONFIRMED
                || bccomp((string) $confirmation->amount, $amount, 4) !== 0
                || $confirmation->payout_account_id !== $accountId) {
                throw DomainApiException::emailConfirmationRequired();
            }

            $account = $this->assertCanWithdraw($customer->customer_id, $accounts, $accountId, $amount);

            $id = (string) Str::uuid();
            $number = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('withdrawal', 'withdrawal_no')) AS n")->n;
            $hold = $this->ledger->hold($id, $number, $customer->customer_id, $amount);

            // The id and the number were reserved for the ledger memo and FK above.
            $withdrawal = Withdrawal::query()->forceCreate([
                'withdrawal_id' => $id,
                'withdrawal_no' => $number,
                'customer_id' => $customer->customer_id,
                'payout_account_id' => $account->payout_account_id,
                'amount' => $amount,
                'state' => WithdrawalState::REQUESTED,
                'hold_txn_id' => $hold->ledger_txn_id,
                'requested_at' => now(),
            ]);

            $confirmation->forceFill(['used_at' => now(), 'withdrawal_id' => $id])->save();

            $this->audit->execute(AuditEvent::WITHDRAWAL_REQUESTED, 'success',
                ['number' => 'WD-'.$number, 'amount' => $amount, 'payout_account_id' => $account->payout_account_id, 'hold_txn_id' => $hold->ledger_txn_id],
                'withdrawal', $id, $ctx, actorCustomerId: $customer->customer_id);

            return $withdrawal->refresh()->setRelation('account', $account);
        });
    }
}
