<?php

namespace App\Actions\Payouts\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutAccountState;
use App\Enums\PayoutEvent;
use App\Enums\PayoutRefusalReason;
use App\Exceptions\DomainApiException;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The name check failed (spec 013 US2, FR-003): `refused`, final. The
 * customer is told the reason, never the staff note.
 */
final class RefusePayoutAccountAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $accountId, PayoutRefusalReason $reason, string $note, ?RequestContext $ctx = null): PayoutAccount
    {
        return DB::transaction(function () use ($actor, $accountId, $reason, $note, $ctx) {
            $customerId = PayoutAccount::query()->whereKey($accountId)->valueOrFail('customer_id');
            $accounts = $this->lockAccounts($customerId);
            $account = $accounts->get($accountId) ?? abort(404);

            if ($account->state !== PayoutAccountState::PENDING_REVIEW) {
                throw DomainApiException::illegalPayoutAccountTransition();
            }

            $this->changeAccount($account, PayoutAccountChangeKind::REFUSED, null, $actor->staff_id,
                PayoutAccountState::REFUSED, [
                    'refusal_reason' => $reason,
                    'refusal_note' => $note,
                    'name_checked_by' => $actor->staff_id,
                    'name_checked_at' => now(),
                ]);

            $this->audit->execute(AuditEvent::PAYOUT_ACCOUNT_REFUSED, 'success',
                ['payout_account_id' => $account->payout_account_id, 'account' => $account->shortLabel(),
                    'customer_ref' => $account->customer()->value('display_ref'), 'refusal_reason' => $reason->value],
                'payout_account', $account->payout_account_id, $ctx, actorStaffId: $actor->staff_id,
                before: ['state' => PayoutAccountState::PENDING_REVIEW->value], reason: $note);

            $this->tellPayout($customerId, new PayoutNotification(PayoutEvent::ACCOUNT_REFUSED,
                account: $account->shortLabel(), reason: $reason->label(), reasonAr: $reason->labelAr()));
            $this->flushPayoutOutbox();

            return $account;
        });
    }
}
