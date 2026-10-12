<?php

namespace App\Actions\Promo;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CreditStatus;
use App\Enums\ReferralState;
use App\Enums\SettingKey;
use App\Models\CommissionCredit;
use App\Models\CommissionCreditEvent;
use App\Models\Referral;
use App\Models\Staff;
use App\Support\Pricing\Settings;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;

/**
 * The hourly sweep (spec 023 T038, research R2, R22), one transaction per item, as the system actor:
 *
 *  - an active credit past its `expires_at` becomes `expired` with its event;
 *  - a referral still `joined` after `referral.credit_expiry_days` from the join becomes `void`
 *    (reason `expired`): the friend never sold in time, so the inviter is never rewarded.
 *
 * Code credits carry no expiry ("anything already in progress carries on").
 */
final class ExpireCommissionCreditsAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly Settings $settings,
    ) {}

    /** @return array{credits: int, referrals: int} */
    public function handle(): array
    {
        $system = Staff::query()->findOrFail(SystemActor::id());
        $credits = 0;
        $referrals = 0;

        $ids = CommissionCredit::query()->where('status', CreditStatus::ACTIVE->value)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('credit_id')->pluck('credit_id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $system, &$credits) {
                $credit = CommissionCredit::query()->whereKey($id)->lockForUpdate()->first();
                if ($credit === null || $credit->status !== CreditStatus::ACTIVE) {
                    return;
                }

                $credit->status = CreditStatus::EXPIRED;
                $credit->ended_at = now();
                $credit->save();

                CommissionCreditEvent::query()->create([
                    'credit_id' => $credit->credit_id,
                    'kind' => 'expired',
                    'amount_left_after_egp' => $credit->amount_left_egp,
                    'uses_left_after' => $credit->uses_left,
                    'reason' => 'expired',
                    'actor_staff_id' => $system->staff_id,
                ]);
                $this->audit->execute(
                    AuditEvent::COMMISSION_CREDIT_EXPIRED, 'success',
                    ['credit_id' => $credit->credit_id, 'source' => $credit->source->value],
                    'commission_credit', $credit->credit_id, actorStaffId: $system->staff_id,
                );
                $credits++;
            });
        }

        $days = $this->settings->integer(SettingKey::REFERRAL_CREDIT_EXPIRY_DAYS);
        $stale = Referral::query()->where('state', ReferralState::JOINED->value)
            ->where('joined_at', '<=', now()->subDays($days))->orderBy('referral_id')->pluck('referral_id');

        foreach ($stale as $id) {
            DB::transaction(function () use ($id, $system, &$referrals) {
                $referral = Referral::query()->whereKey($id)->lockForUpdate()->first();
                if ($referral === null || $referral->state !== ReferralState::JOINED) {
                    return;
                }

                $referral->state = ReferralState::VOID;
                $referral->void_reason = 'expired';
                $referral->save();

                $this->audit->execute(
                    AuditEvent::REFERRAL_VOID, 'success',
                    ['referral_id' => $referral->referral_id, 'reason' => 'expired'],
                    'referral', $referral->referral_id, actorStaffId: $system->staff_id,
                );
                $referrals++;
            });
        }

        return ['credits' => $credits, 'referrals' => $referrals];
    }
}
