<?php

namespace App\Enums;

/**
 * The settings catalogue (spec 005, schema §3). Keys come with releases; the
 * Dashboard only changes their values. Each key knows its permission group
 * and its allowed range, so no caller repeats either.
 */
enum SettingKey: string
{
    case COMMISSION_GOLD_PCT = 'commission.gold_pct';
    case COMMISSION_STONE_PCT = 'commission.stone_pct';
    case COMMISSION_MINIMUM_EGP = 'commission.minimum_egp';
    case VAT_PCT = 'vat.pct';
    case DEPOSIT_BUYER_PCT = 'deposit.buyer_pct';
    case DEPOSIT_SELLER_FORFEIT_SHARE_PCT = 'deposit.seller_forfeit_share_pct';
    case DEADLINE_SELLER_REPLY_HOURS = 'deadline.seller_reply_hours';
    case DEADLINE_REACH_BRANCH_WORKING_HOURS = 'deadline.reach_branch_working_hours';
    case DEADLINE_BUYER_PAY_DAYS = 'deadline.buyer_pay_days';
    case DEADLINE_COLLECT_WEEKS = 'deadline.collect_weeks';
    case DEADLINE_SELLER_RETURN_WEEKS = 'deadline.seller_return_weeks';
    case DEADLINE_FREE_RELIST_WORKING_HOURS = 'deadline.free_relist_working_hours';
    case WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS = 'withdrawal.account_change_pause_hours';
    case INSPECTION_WEIGHT_TOLERANCE_PCT = 'inspection.weight_tolerance_pct';
    case MARKETMAKER_MIN_LIST_AGE_DAYS = 'marketmaker.min_list_age_days';
    case PAYOUT_FIRST_SALE_CAP_EGP = 'payout.first_sale_cap_egp';
    case SUSPENSION_CANCELLATIONS_THRESHOLD = 'suspension.cancellations_threshold';
    case FLAG_PATTERN_TXN_THRESHOLD = 'flag.pattern_txn_threshold';
    case COMPENSATION_CAP_PER_PAYMENT_EGP = 'compensation.cap_per_payment_egp';
    case COMPENSATION_CAP_PER_DAY_EGP = 'compensation.cap_per_day_egp';
    case MANUALPRICE_CONFIRM_DEVIATION_PCT = 'manualprice.confirm_deviation_pct';
    case MANUALPRICE_PENDING_EXPIRY_HOURS = 'manualprice.pending_expiry_hours';
    case MANUALPRICE_CONFIRMER_MUST_DIFFER = 'manualprice.confirmer_must_differ';
    case PRICEFEED_STALE_AFTER_MINUTES = 'pricefeed.stale_after_minutes';

    /** Money and price rules (Finance + CEO) or operations (founders) — research R1. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::COMMISSION_GOLD_PCT, self::COMMISSION_STONE_PCT, self::COMMISSION_MINIMUM_EGP, self::VAT_PCT,
            self::MANUALPRICE_CONFIRM_DEVIATION_PCT, self::MANUALPRICE_PENDING_EXPIRY_HOURS,
            self::MANUALPRICE_CONFIRMER_MUST_DIFFER, self::PRICEFEED_STALE_AFTER_MINUTES,
            self::COMPENSATION_CAP_PER_PAYMENT_EGP, self::COMPENSATION_CAP_PER_DAY_EGP,
            self::PAYOUT_FIRST_SALE_CAP_EGP => SettingGroup::RATES,
            default => SettingGroup::OPERATIONS,
        };
    }

    public function isBool(): bool
    {
        return $this === self::MANUALPRICE_CONFIRMER_MUST_DIFFER;
    }

    /** Whole numbers only (durations and counts). */
    public function isInteger(): bool
    {
        return match ($this) {
            self::DEADLINE_SELLER_REPLY_HOURS, self::DEADLINE_REACH_BRANCH_WORKING_HOURS,
            self::DEADLINE_BUYER_PAY_DAYS, self::DEADLINE_COLLECT_WEEKS, self::DEADLINE_SELLER_RETURN_WEEKS,
            self::DEADLINE_FREE_RELIST_WORKING_HOURS, self::WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS,
            self::MARKETMAKER_MIN_LIST_AGE_DAYS, self::SUSPENSION_CANCELLATIONS_THRESHOLD,
            self::FLAG_PATTERN_TXN_THRESHOLD, self::MANUALPRICE_PENDING_EXPIRY_HOURS,
            self::PRICEFEED_STALE_AFTER_MINUTES => true,
            default => false,
        };
    }

    /** Inclusive lower bound (numeric keys). */
    public function min(): ?string
    {
        return match ($this) {
            self::MANUALPRICE_CONFIRMER_MUST_DIFFER => null,
            self::DEADLINE_SELLER_REPLY_HOURS, self::DEADLINE_REACH_BRANCH_WORKING_HOURS,
            self::DEADLINE_BUYER_PAY_DAYS, self::DEADLINE_COLLECT_WEEKS, self::DEADLINE_SELLER_RETURN_WEEKS,
            self::SUSPENSION_CANCELLATIONS_THRESHOLD, self::FLAG_PATTERN_TXN_THRESHOLD,
            self::MANUALPRICE_PENDING_EXPIRY_HOURS, self::PRICEFEED_STALE_AFTER_MINUTES => '1',
            default => '0',
        };
    }

    /** Inclusive upper bound, or null for none (numeric keys). */
    public function max(): ?string
    {
        return match ($this) {
            self::COMMISSION_GOLD_PCT, self::COMMISSION_STONE_PCT, self::VAT_PCT,
            self::DEPOSIT_BUYER_PCT, self::DEPOSIT_SELLER_FORFEIT_SHARE_PCT,
            self::INSPECTION_WEIGHT_TOLERANCE_PCT, self::MANUALPRICE_CONFIRM_DEVIATION_PCT => '100',
            self::MANUALPRICE_PENDING_EXPIRY_HOURS => '168',
            self::PRICEFEED_STALE_AFTER_MINUTES => '1440',
            default => null,
        };
    }

    public function permission(): StaffPermission
    {
        return $this->group() === SettingGroup::RATES
            ? StaffPermission::PRICING_RATES_MANAGE
            : StaffPermission::SETTINGS_MANAGE;
    }
}
