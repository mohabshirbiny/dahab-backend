<?php

namespace App\Support\BuyRequests;

use App\Enums\SettingKey;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;

/**
 * The deposit and the price tolerance of a buy request (spec 011 FR-002,
 * FR-003, research R5–R6). The one place both numbers are worked out, from
 * settings read live: the market shows the same deposit a request holds.
 */
final class DepositRule
{
    public function __construct(private readonly Settings $settings) {}

    /** `deposit.buyer_pct` of the price, half-up to the piastre, as a 4-dp string. */
    public function deposit(string $price): string
    {
        $raw = Money::percent($price, $this->settings->numeric(SettingKey::DEPOSIT_BUYER_PCT));

        return bcadd(bcadd($raw, '0.005', Money::SCALE), '0', 2).'00';
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
