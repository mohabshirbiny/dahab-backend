<?php

namespace App\Actions\Withdrawals\Customer;

use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Jobs\NotifyCustomerJob;
use App\Models\Customer;
use App\Models\WithdrawalConfirmation;
use App\Notifications\WithdrawalConfirmationNotification;
use App\Support\Pricing\Money;
use App\Support\Withdrawals\ConfirmationTokens;
use Illuminate\Support\Facades\DB;

/**
 * Step one of the email second-check (Part 1 §2.4; spec 013 FR-007, research
 * R5): the gates are checked first so the customer is never sent a link that
 * cannot be used; any earlier open confirmation is replaced; a new one, tied to
 * the amount and the account, is stored by its HMAC and its link emailed to the
 * customer's confirmed address after commit.
 */
final class RequestWithdrawalConfirmationAction
{
    use WorksWithdrawals;

    public function handle(Customer $customer, string $amount, string $accountId): WithdrawalConfirmation
    {
        $amount = Money::fixed4($amount);

        return DB::transaction(function () use ($customer, $amount, $accountId) {
            $accounts = $this->lockAccounts($customer->customer_id);
            $account = $this->assertCanWithdraw($customer->customer_id, $accounts, $accountId, $amount);

            WithdrawalConfirmation::query()->where('customer_id', $customer->customer_id)
                ->whereNull('used_at')->whereNull('replaced_at')
                ->update(['replaced_at' => now()]);

            $token = ConfirmationTokens::generate();
            $minutes = (int) config('dahab-withdrawals.confirmation_ttl_minutes');

            $confirmation = WithdrawalConfirmation::query()->create([
                'customer_id' => $customer->customer_id,
                'payout_account_id' => $account->payout_account_id,
                'amount' => $amount,
                'token_hash' => ConfirmationTokens::hash($token),
                'expires_at' => now()->addMinutes($minutes),
            ]);

            $notification = new WithdrawalConfirmationNotification(ConfirmationTokens::link($token), $amount, $account->shortLabel(), $minutes);
            DB::afterCommit(fn () => NotifyCustomerJob::dispatch($customer->customer_id, $notification));

            return $confirmation->setRelation('account', $account);
        });
    }
}
