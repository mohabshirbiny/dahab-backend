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

    /** Spec 011: how far the confirmed price may be from the fresh one before a buy request is refused. */
    case BUYREQUEST_PRICE_TOLERANCE_PCT = 'buyrequest.price_tolerance_pct';

    /** Spec 017: most pieces one customer may keep in Saved (user decision 2026-10-06). */
    case SAVED_MAX_PER_CUSTOMER = 'saved.max_per_customer';

    /** Spec 019 US7: what customers can see (the design's "What customers can see"). */
    case VISIBILITY_RAPAPORT_REFERENCE = 'visibility.rapaport_reference';
    case VISIBILITY_PAYOUT_AVERAGES = 'visibility.payout_averages';
    case VISIBILITY_LISTING_STATS = 'visibility.listing_stats';
    case VISIBILITY_SHARING = 'visibility.sharing';

    /** Spec 023: the first-sale advance (Finance sign-off D23-5 before it is switched on). */
    case PAYOUT_FIRST_SALE_ENABLED = 'payout.first_sale_enabled';
    case PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP = 'payout.first_sale_exposure_ceiling_egp';
    case PAYOUT_FIRST_SALE_COMP_OFFSETS_WRITEOFF = 'payout.first_sale_comp_offsets_writeoff';

    /** Spec 023: seller-side codes, referral and the market-maker page. */
    case PROMO_SELLER_CODES_ENABLED = 'promo.seller_codes_enabled';
    case REFERRAL_ENABLED = 'referral.enabled';
    case REFERRAL_FRIEND_PERCENT_OFF = 'referral.friend_percent_off';
    case REFERRAL_INVITER_REWARD_EGP = 'referral.inviter_reward_egp';
    case REFERRAL_FRIEND_SALES_COVERED = 'referral.friend_sales_covered';
    case REFERRAL_CREDIT_EXPIRY_DAYS = 'referral.credit_expiry_days';
    case REFERRAL_MAX_USES_PER_CODE = 'referral.max_uses_per_code';
    case REFERRAL_MAX_INVITATIONS_PER_ACCOUNT = 'referral.max_invitations_per_account';
    case REFERRAL_MAX_PER_DEVICE = 'referral.max_per_device';
    case REFERRAL_MIN_FRIEND_SALE_EGP = 'referral.min_friend_sale_egp';
    case MARKETMAKER_REQUIRE_APPROVAL = 'marketmaker.require_approval';
    case MARKETMAKER_MAKING_CHARGE_WARN_PCT = 'marketmaker.making_charge_warn_pct';
    case LISTING_VIEW_RETENTION_DAYS = 'listing.view_retention_days';

    /** What the seller pays for IGI's certificate, by the certificate they bring (OI-3.2); shown on the sell form. */
    case CERTIFICATE_IGI_COST_EGP = 'certificate.igi_cost_egp';
    case CERTIFICATE_OTHER_LAB_COST_EGP = 'certificate.other_lab_cost_egp';
    case CERTIFICATE_NONE_COST_EGP = 'certificate.none_cost_egp';

    /** A staff member on a device not yet known confirms it by an SMS code; a founder's first device too. */
    case AUTH_STAFF_NEW_DEVICE_SMS = 'auth.staff_new_device_sms';

    /** Money and price rules (Finance + CEO) or operations (founders) — research R1. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::COMMISSION_GOLD_PCT, self::COMMISSION_STONE_PCT, self::COMMISSION_MINIMUM_EGP, self::VAT_PCT,
            self::MANUALPRICE_CONFIRM_DEVIATION_PCT, self::MANUALPRICE_PENDING_EXPIRY_HOURS,
            self::MANUALPRICE_CONFIRMER_MUST_DIFFER, self::PRICEFEED_STALE_AFTER_MINUTES,
            self::COMPENSATION_CAP_PER_PAYMENT_EGP, self::COMPENSATION_CAP_PER_DAY_EGP,
            self::PAYOUT_FIRST_SALE_CAP_EGP,
            self::PAYOUT_FIRST_SALE_ENABLED, self::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP,
            self::PAYOUT_FIRST_SALE_COMP_OFFSETS_WRITEOFF, self::PROMO_SELLER_CODES_ENABLED,
            self::REFERRAL_ENABLED, self::REFERRAL_FRIEND_PERCENT_OFF, self::REFERRAL_INVITER_REWARD_EGP,
            self::REFERRAL_FRIEND_SALES_COVERED, self::REFERRAL_CREDIT_EXPIRY_DAYS, self::REFERRAL_MAX_USES_PER_CODE,
            self::REFERRAL_MAX_INVITATIONS_PER_ACCOUNT, self::REFERRAL_MAX_PER_DEVICE,
            self::REFERRAL_MIN_FRIEND_SALE_EGP,
            self::CERTIFICATE_IGI_COST_EGP, self::CERTIFICATE_OTHER_LAB_COST_EGP,
            self::CERTIFICATE_NONE_COST_EGP => SettingGroup::RATES,
            self::VISIBILITY_RAPAPORT_REFERENCE, self::VISIBILITY_PAYOUT_AVERAGES,
            self::VISIBILITY_LISTING_STATS, self::VISIBILITY_SHARING => SettingGroup::VISIBILITY,
            default => SettingGroup::OPERATIONS,
        };
    }

    public function isBool(): bool
    {
        return match ($this) {
            self::MANUALPRICE_CONFIRMER_MUST_DIFFER, self::VISIBILITY_RAPAPORT_REFERENCE, self::VISIBILITY_PAYOUT_AVERAGES,
            self::VISIBILITY_LISTING_STATS, self::VISIBILITY_SHARING,
            self::PAYOUT_FIRST_SALE_ENABLED, self::PAYOUT_FIRST_SALE_COMP_OFFSETS_WRITEOFF,
            self::PROMO_SELLER_CODES_ENABLED, self::REFERRAL_ENABLED, self::MARKETMAKER_REQUIRE_APPROVAL,
            self::AUTH_STAFF_NEW_DEVICE_SMS => true,
            default => false,
        };
    }

    /** The key's name on the Switches API (`rapaport_reference`), for the visibility keys. */
    public function visibilityName(): ?string
    {
        return str_starts_with($this->value, 'visibility.') ? substr($this->value, strlen('visibility.')) : null;
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
            self::PRICEFEED_STALE_AFTER_MINUTES, self::SAVED_MAX_PER_CUSTOMER,
            self::REFERRAL_FRIEND_SALES_COVERED, self::REFERRAL_CREDIT_EXPIRY_DAYS, self::REFERRAL_MAX_USES_PER_CODE,
            self::REFERRAL_MAX_INVITATIONS_PER_ACCOUNT, self::REFERRAL_MAX_PER_DEVICE,
            self::LISTING_VIEW_RETENTION_DAYS => true,
            default => false,
        };
    }

    /** Inclusive lower bound (numeric keys). */
    public function min(): ?string
    {
        return match ($this) {
            self::MANUALPRICE_CONFIRMER_MUST_DIFFER, self::VISIBILITY_RAPAPORT_REFERENCE, self::VISIBILITY_PAYOUT_AVERAGES,
            self::VISIBILITY_LISTING_STATS, self::VISIBILITY_SHARING,
            self::PAYOUT_FIRST_SALE_ENABLED, self::PAYOUT_FIRST_SALE_COMP_OFFSETS_WRITEOFF,
            self::PROMO_SELLER_CODES_ENABLED, self::REFERRAL_ENABLED, self::MARKETMAKER_REQUIRE_APPROVAL,
            self::AUTH_STAFF_NEW_DEVICE_SMS => null,
            // A share of a commission strictly between nothing and all of it (data-model §7).
            self::REFERRAL_FRIEND_PERCENT_OFF => '0.0001',
            // An amount that must be there (not zero).
            self::REFERRAL_INVITER_REWARD_EGP => '0.0001',
            self::REFERRAL_FRIEND_SALES_COVERED, self::REFERRAL_CREDIT_EXPIRY_DAYS, self::REFERRAL_MAX_USES_PER_CODE,
            self::REFERRAL_MAX_INVITATIONS_PER_ACCOUNT, self::REFERRAL_MAX_PER_DEVICE,
            self::LISTING_VIEW_RETENTION_DAYS => '1',
            self::DEADLINE_SELLER_REPLY_HOURS, self::DEADLINE_REACH_BRANCH_WORKING_HOURS,
            self::DEADLINE_BUYER_PAY_DAYS, self::DEADLINE_COLLECT_WEEKS, self::DEADLINE_SELLER_RETURN_WEEKS,
            self::SUSPENSION_CANCELLATIONS_THRESHOLD, self::FLAG_PATTERN_TXN_THRESHOLD,
            self::MANUALPRICE_PENDING_EXPIRY_HOURS, self::PRICEFEED_STALE_AFTER_MINUTES,
            self::SAVED_MAX_PER_CUSTOMER => '1',
            default => '0',
        };
    }

    /** Inclusive upper bound, or null for none (numeric keys). */
    public function max(): ?string
    {
        return match ($this) {
            self::COMMISSION_GOLD_PCT, self::COMMISSION_STONE_PCT, self::VAT_PCT,
            self::DEPOSIT_BUYER_PCT, self::DEPOSIT_SELLER_FORFEIT_SHARE_PCT,
            self::INSPECTION_WEIGHT_TOLERANCE_PCT, self::MANUALPRICE_CONFIRM_DEVIATION_PCT,
            self::BUYREQUEST_PRICE_TOLERANCE_PCT => '100',
            self::MANUALPRICE_PENDING_EXPIRY_HOURS => '168',
            self::PRICEFEED_STALE_AFTER_MINUTES => '1440',
            self::SAVED_MAX_PER_CUSTOMER => '1000',
            self::REFERRAL_FRIEND_PERCENT_OFF => '99.9999',
            self::MARKETMAKER_MAKING_CHARGE_WARN_PCT => '1000',
            default => null,
        };
    }

    public function permission(): StaffPermission
    {
        return match ($this->group()) {
            SettingGroup::RATES => StaffPermission::PRICING_RATES_MANAGE,
            SettingGroup::VISIBILITY => StaffPermission::VISIBILITY_MANAGE,
            default => StaffPermission::SETTINGS_MANAGE,
        };
    }
}
