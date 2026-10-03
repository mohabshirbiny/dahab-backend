<?php

namespace App\Actions\Payouts\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutAccountState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * "Keep it after all" (spec 013 US3): a `removing` account goes back to
 * active. Nothing changed where money goes, so there is no pause.
 */
final class KeepPayoutAccountAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $customer, string $accountId, ?RequestContext $ctx = null): PayoutAccount
    {
        return DB::transaction(function () use ($customer, $accountId, $ctx) {
            $accounts = $this->lockAccounts($customer->customer_id);
            $account = $accounts->get($accountId) ?? abort(404);

            if ($account->state !== PayoutAccountState::REMOVING) {
                throw DomainApiException::illegalPayoutAccountTransition();
            }

            $this->changeAccount($account, PayoutAccountChangeKind::KEPT, $customer->customer_id, null,
                PayoutAccountState::ACTIVE, ['removal_requested_at' => null]);

            $this->audit->execute(AuditEvent::PAYOUT_ACCOUNT_KEPT, 'success',
                ['payout_account_id' => $account->payout_account_id, 'account' => $account->shortLabel()],
                'payout_account', $account->payout_account_id, $ctx, actorCustomerId: $customer->customer_id);

            return $account;
        });
    }
}
