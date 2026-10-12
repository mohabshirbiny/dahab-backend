<?php

namespace App\Actions\Referral;

use App\Enums\CreditStatus;
use App\Enums\ReferralState;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\CommissionCredit;
use App\Models\Customer;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Support\DatabaseActor;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The customer's *Invite a friend* screen and its counter (spec 023 research R22, R24;
 * contract referral-api §1–§3). The invitation code is created on the first read while the
 * programme is on; while it is off nothing is created and the answer is `enabled: false`.
 * A credit is never money: it is shown here and used only on a later commission.
 */
final class ReferralScreenAction
{
    /** No 0, O, 1, I: a code read aloud or typed from a screenshot is not misread. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public function show(Customer $customer): array
    {
        $enabled = $this->settings->bool(SettingKey::REFERRAL_ENABLED);
        $terms = [
            'friend_percent_off' => Money::fixed4($this->settings->numeric(SettingKey::REFERRAL_FRIEND_PERCENT_OFF)),
            'friend_sales_covered' => $this->settings->integer(SettingKey::REFERRAL_FRIEND_SALES_COVERED),
            'inviter_reward_egp' => Money::fixed4($this->settings->numeric(SettingKey::REFERRAL_INVITER_REWARD_EGP)),
            'credit_expiry_days' => $this->settings->integer(SettingKey::REFERRAL_CREDIT_EXPIRY_DAYS),
            'min_friend_sale_egp' => Money::fixed4($this->settings->numeric(SettingKey::REFERRAL_MIN_FRIEND_SALE_EGP)),
        ];

        if (! $enabled) {
            return ['enabled' => false, 'code' => null, 'invites_sent' => 0, 'joined' => 0, 'sold' => 0,
                'earned_egp' => '0.0000', 'used_egp' => '0.0000', 'available_egp' => '0.0000', 'available_until' => null, 'terms' => $terms];
        }

        $code = $this->codeOf($customer);

        $referrals = Referral::query()->where('inviter_id', $customer->customer_id)->get(['state']);
        $credits = CommissionCredit::query()->where('customer_id', $customer->customer_id)->where('source', 'referral_inviter')->get();
        $usable = $credits->filter(fn (CommissionCredit $c) => $c->status === CreditStatus::ACTIVE && ($c->expires_at === null || $c->expires_at->isFuture()));
        $used = DB::table('commission_credit_event')->whereIn('credit_id', $credits->pluck('credit_id'))->where('kind', 'spent')->sum('reduction_egp');

        return [
            'enabled' => true,
            'code' => $code->code,
            'invites_sent' => $code->shares_count,
            'joined' => $referrals->filter(fn ($r) => $r->state !== ReferralState::VOID)->count(),
            'sold' => $referrals->filter(fn ($r) => $r->state === ReferralState::REWARDED)->count(),
            'earned_egp' => Money::fixed4((string) $credits->sum('amount_egp')),
            'used_egp' => Money::fixed4((string) $used),
            'available_egp' => Money::fixed4((string) $usable->sum('amount_left_egp')),
            'available_until' => $usable->pluck('expires_at')->filter()->min()?->toIso8601String(),
            'terms' => $terms,
        ];
    }

    /** One Share/Copy tap, counted up to `referral.max_invitations_per_account`. */
    public function share(Customer $customer): int
    {
        if (! $this->settings->bool(SettingKey::REFERRAL_ENABLED)) {
            throw DomainApiException::referralNotOpen();
        }

        return DatabaseActor::promo(fn () => DB::transaction(function () use ($customer) {
            $code = ReferralCode::query()->whereKey($customer->customer_id)->lockForUpdate()->first() ?? $this->create($customer);
            $max = $this->settings->integer(SettingKey::REFERRAL_MAX_INVITATIONS_PER_ACCOUNT);
            if ($code->shares_count < $max) {
                $code->shares_count = $code->shares_count + 1;
                $code->save();
            }

            return $code->shares_count;
        }));
    }

    /** @return list<array<string, mixed>> the customer's credits, active first, newest first, at most 100 */
    public function credits(Customer $customer): array
    {
        return CommissionCredit::query()->where('customer_id', $customer->customer_id)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderByDesc('granted_at')->limit(100)->get()
            ->map(fn (CommissionCredit $c) => [
                'credit_id' => $c->credit_id,
                'source' => $c->source->value,
                'kind' => $c->kind->value,
                'percent_off' => $c->percent_off === null ? null : Money::fixed4((string) $c->percent_off),
                'amount_egp' => $c->amount_egp === null ? null : Money::fixed4((string) $c->amount_egp),
                'amount_left_egp' => $c->amount_left_egp === null ? null : Money::fixed4((string) $c->amount_left_egp),
                'uses_left' => $c->uses_left,
                'listing_id' => $c->listing_id,
                'expires_at' => $c->expires_at?->toIso8601String(),
                'status' => $c->status->value,
            ])->values()->all();
    }

    private function codeOf(Customer $customer): ReferralCode
    {
        return ReferralCode::query()->whereKey($customer->customer_id)->first()
            ?? DatabaseActor::promo(fn () => DB::transaction(fn () => $this->create($customer)));
    }

    private function create(Customer $customer): ReferralCode
    {
        for ($i = 0; $i < 20; $i++) {
            $value = '';
            for ($n = 0; $n < 8; $n++) {
                $value .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            // Never equal to a promo code: the sell-form field takes both.
            if (DB::table('promo_code')->where('code', $value)->exists()) {
                continue;
            }

            try {
                return DB::transaction(fn () => ReferralCode::query()->create(['customer_id' => $customer->customer_id, 'code' => $value])->refresh());
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') !== '23505') {
                    throw $e;
                }
                // A concurrent creation for the same customer: read it back; a code clash: try another.
                $existing = ReferralCode::query()->whereKey($customer->customer_id)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        throw new \RuntimeException('Could not find a free invitation code.');
    }
}
