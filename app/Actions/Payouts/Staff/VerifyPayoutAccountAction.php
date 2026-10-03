<?php

namespace App\Actions\Payouts\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutAccountState;
use App\Enums\PayoutEvent;
use App\Exceptions\DomainApiException;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Models\WithdrawalPause;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The name check passed (spec 013 US2, FR-003; Part 2 §9
 * `POST /admin/payout-accounts/{id}/verify`): the account becomes usable.
 * When the customer has no account in use it becomes the one in use — a
 * change (cancel + pause) unless it is the customer's first ever.
 */
final class VerifyPayoutAccountAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{account: PayoutAccount, pause: ?WithdrawalPause, cancelled: list<string>} */
    public function handle(Staff $actor, string $accountId, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($actor, $accountId, $ctx) {
            $customerId = PayoutAccount::query()->whereKey($accountId)->valueOrFail('customer_id');
            $accounts = $this->lockAccounts($customerId);
            $account = $accounts->get($accountId) ?? abort(404);

            if ($account->state !== PayoutAccountState::PENDING_REVIEW) {
                throw DomainApiException::illegalPayoutAccountTransition();
            }

            $this->changeAccount($account, PayoutAccountChangeKind::VERIFIED, null, $actor->staff_id,
                PayoutAccountState::ACTIVE, [
                    'name_checked_by' => $actor->staff_id,
                    'name_checked_at' => now(),
                    'activated_at' => now(),
                ]);

            $this->audit->execute(AuditEvent::PAYOUT_ACCOUNT_VERIFIED, 'success',
                ['payout_account_id' => $account->payout_account_id, 'account' => $account->shortLabel(),
                    'customer_ref' => $account->customer()->value('display_ref')],
                'payout_account', $account->payout_account_id, $ctx, actorStaffId: $actor->staff_id,
                before: ['state' => PayoutAccountState::PENDING_REVIEW->value]);

            $this->tellPayout($customerId, new PayoutNotification(PayoutEvent::ACCOUNT_VERIFIED, account: $account->shortLabel()));

            $result = ['pause' => null, 'cancelled' => []];
            if (! $accounts->contains(fn (PayoutAccount $a) => $a->is_in_use)) {
                $result = $this->makeInUse($accounts, $account, null, $actor->staff_id, $ctx);
            }

            $this->flushPayoutOutbox();

            return ['account' => $account] + $result;
        });
    }
}
