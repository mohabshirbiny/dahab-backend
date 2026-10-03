<?php

namespace App\Actions\Payouts\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutAccountState;
use App\Enums\PayoutEvent;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Models\Withdrawal;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * "Remove" / "Cancel this request" (spec 013 US3, FR-004). A request under
 * review is removed at once. A verified account is removed at once unless a
 * withdrawal not yet released goes to it: then it is `removing` — it keeps its
 * in-use flag, refuses new confirmations and withdrawals, and becomes
 * `removed` in the operation that ends its last open withdrawal (analysis I4).
 */
final class RemovePayoutAccountAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $customer, string $accountId, ?RequestContext $ctx = null): PayoutAccount
    {
        return DB::transaction(function () use ($customer, $accountId, $ctx) {
            $accounts = $this->lockAccounts($customer->customer_id);
            $account = $accounts->get($accountId) ?? abort(404);
            $me = $customer->customer_id;

            if ($account->state === PayoutAccountState::PENDING_REVIEW) {
                $this->changeAccount($account, PayoutAccountChangeKind::REQUEST_CANCELLED, $me, null,
                    PayoutAccountState::REMOVED, ['removed_at' => now()]);
                $event = AuditEvent::PAYOUT_ACCOUNT_REMOVED;
            } elseif ($account->state === PayoutAccountState::ACTIVE) {
                $open = Withdrawal::query()->where('payout_account_id', $account->payout_account_id)
                    ->whereIn('state', WithdrawalState::openValues())->exists();

                if ($open) {
                    $this->changeAccount($account, PayoutAccountChangeKind::REMOVAL_SCHEDULED, $me, null,
                        PayoutAccountState::REMOVING, ['removal_requested_at' => now()]);
                    $event = AuditEvent::PAYOUT_ACCOUNT_REMOVAL_SCHEDULED;
                } else {
                    $this->changeAccount($account, PayoutAccountChangeKind::REMOVED, $me, null,
                        PayoutAccountState::REMOVED, ['is_in_use' => false, 'removed_at' => now()]);
                    $event = AuditEvent::PAYOUT_ACCOUNT_REMOVED;
                    $this->tellPayout($me, new PayoutNotification(PayoutEvent::ACCOUNT_REMOVED, account: $account->shortLabel()));
                }
            } else {
                throw DomainApiException::illegalPayoutAccountTransition();
            }

            $this->audit->execute($event, 'success',
                ['payout_account_id' => $account->payout_account_id, 'account' => $account->shortLabel(), 'state' => $account->state->value],
                'payout_account', $account->payout_account_id, $ctx, actorCustomerId: $me);

            $this->flushPayoutOutbox();

            return $account;
        });
    }
}
