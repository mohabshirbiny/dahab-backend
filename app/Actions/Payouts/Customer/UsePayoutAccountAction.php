<?php

namespace App\Actions\Payouts\Customer;

use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\PayoutAccountState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\WithdrawalPause;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * "Use this one" (spec 013 US3, FR-005): a verified account becomes the one in
 * use. Unless it is the customer's first account ever in use, every
 * withdrawal not yet released is cancelled with its money returned, and new
 * withdrawals pause for `withdrawal.account_change_pause_hours`. The customer
 * is told on their current phone and email.
 */
final class UsePayoutAccountAction
{
    use WorksWithdrawals;

    /** @return array{pause: ?WithdrawalPause, cancelled: list<string>} */
    public function handle(Customer $customer, string $accountId, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($customer, $accountId, $ctx) {
            $accounts = $this->lockAccounts($customer->customer_id);
            $account = $accounts->get($accountId) ?? abort(404);

            if ($account->state !== PayoutAccountState::ACTIVE || $account->is_in_use) {
                throw DomainApiException::illegalPayoutAccountTransition();
            }

            $result = $this->makeInUse($accounts, $account, $customer->customer_id, null, $ctx);
            $this->flushPayoutOutbox();

            return $result;
        });
    }
}
