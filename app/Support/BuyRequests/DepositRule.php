<?php

namespace App\Support\BuyRequests;

use App\Enums\SettingKey;
use App\Models\PromoCode;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;

/**
 * The deposit and the price tolerance of a buy request (spec 011 FR-002,
 * FR-003, research R5–R6). The one place both numbers are worked out, from
 * settings read live: the market shows the same deposit a request holds.
 */
final class DepositRule
{
    /** The least a discounted deposit can be: the ledger holds an amount above zero. */
    public const MINIMUM_EGP = '1.0000';

    public function __construct(private readonly Settings $settings) {}

    /** `deposit.buyer_pct` of the price, half-up to the piastre, as a 4-dp string. */
    public function deposit(string $price): string
    {
        $raw = Money::percent($price, $this->settings->numeric(SettingKey::DEPOSIT_BUYER_PCT));

        return bcadd(bcadd($raw, '0.005', Money::SCALE), '0', 2).'00';
    }

    /**
     * The deposit after a buyer code (diamond and gold-with-diamond): a percentage of the deposit or a
     * fixed amount off it, never below `MINIMUM_EGP` (a deposit of nothing cannot be held). Any other
     * code, or none, leaves it as it is.
     */
    public function discounted(string $deposit, ?PromoCode $promo): string
    {
        if ($promo === null || $promo->kind !== 'buyer' || $promo->deposit_mode === 'none') {
            return $deposit;
        }

        $off = $promo->deposit_mode === 'percent'
            ? Money::percent($deposit, (string) $promo->deposit_percent)
            : (string) $promo->deposit_amount_egp;
        $left = Money::sub($deposit, $off);
        $floor = Money::cmp($deposit, self::MINIMUM_EGP) < 0 ? $deposit : self::MINIMUM_EGP;
        $left = Money::cmp($left, $floor) < 0 ? $floor : $left;

        return bcadd(bcadd($left, '0.005', Money::SCALE), '0', 2).'00';
    }

    /** Whether the confirmed price is within `buyrequest.price_tolerance_pct` of the fresh one. */
    public function withinTolerance(string $confirmed, string $fresh): bool
    {
        $diff = Money::sub($confirmed, $fresh);
        if (bccomp($diff, '0', Money::SCALE) < 0) {
            $diff = bcmul($diff, '-1', Money::SCALE);
        }

        $limit = Money::percent($fresh, $this->settings->numeric(SettingKey::BUYREQUEST_PRICE_TOLERANCE_PCT));

        return bccomp($diff, $limit, Money::SCALE) <= 0;
    }
}
