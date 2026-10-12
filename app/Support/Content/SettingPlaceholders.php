<?php

namespace App\Support\Content;

use App\Enums\SettingKey;
use App\Models\Setting;

/**
 * The fixed list of `{placeholders}` that stand for a setting (spec 019 R5, FR-009): a text
 * never types a number that a setting owns, it names it, and the number is filled in with the
 * live value — by the app from the bundle, or by a notification when it is sent.
 *
 * The same list serves app texts, the FAQ and notification templates.
 */
final class SettingPlaceholders
{
    /** @var array<string, SettingKey> placeholder name → the setting behind it, in a fixed order */
    public const MAP = [
        'commission_gold_pct' => SettingKey::COMMISSION_GOLD_PCT,
        'commission_stone_pct' => SettingKey::COMMISSION_STONE_PCT,
        'commission_minimum_egp' => SettingKey::COMMISSION_MINIMUM_EGP,
        'vat_pct' => SettingKey::VAT_PCT,
        'deposit_buyer_pct' => SettingKey::DEPOSIT_BUYER_PCT,
        'reach_branch_hours' => SettingKey::DEADLINE_REACH_BRANCH_WORKING_HOURS,
        'buyer_pay_days' => SettingKey::DEADLINE_BUYER_PAY_DAYS,
        'collect_weeks' => SettingKey::DEADLINE_COLLECT_WEEKS,
        'seller_return_weeks' => SettingKey::DEADLINE_SELLER_RETURN_WEEKS,
        'free_relist_hours' => SettingKey::DEADLINE_FREE_RELIST_WORKING_HOURS,
        'withdrawal_pause_hours' => SettingKey::WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS,
        'weight_tolerance_pct' => SettingKey::INSPECTION_WEIGHT_TOLERANCE_PCT,
        'saved_max' => SettingKey::SAVED_MAX_PER_CUSTOMER,
        // Spec 023 R28: the words of the code card and the Invite screen follow the settings.
        'first_sale_cap' => SettingKey::PAYOUT_FIRST_SALE_CAP_EGP,
        'referral_friend_pct' => SettingKey::REFERRAL_FRIEND_PERCENT_OFF,
        'referral_reward_egp' => SettingKey::REFERRAL_INVITER_REWARD_EGP,
        'referral_expiry_days' => SettingKey::REFERRAL_CREDIT_EXPIRY_DAYS,
        'referral_friend_sales' => SettingKey::REFERRAL_FRIEND_SALES_COVERED,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The current value of every setting placeholder, formatted for a sentence, in one query.
     *
     * @return array<string, string> name → value (`20`, not `20.0000`)
     */
    public function values(): array
    {
        $rows = Setting::query()
            ->whereIn('setting_key', array_map(fn (SettingKey $k) => $k->value, self::MAP))
            ->pluck('value_numeric', 'setting_key');

        $values = [];
        foreach (self::MAP as $name => $key) {
            $raw = $rows[$key->value] ?? null;
            if ($raw !== null) {
                $values[$name] = self::format((string) $raw);
            }
        }

        return $values;
    }

    /** Fingerprint of the current values: it moves when any number a text may state moves (R3). */
    public function fingerprint(?array $values = null): string
    {
        return sha1((string) json_encode($values ?? $this->values()));
    }

    /** `20.0000` → `20`, `0.5000` → `0.5`: a number as people write it. */
    public static function format(string $numeric): string
    {
        if (! str_contains($numeric, '.')) {
            return $numeric;
        }

        return rtrim(rtrim($numeric, '0'), '.');
    }
}
