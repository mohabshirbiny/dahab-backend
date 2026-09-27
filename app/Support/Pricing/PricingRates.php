<?php

namespace App\Support\Pricing;

/** The commission and VAT settings a breakdown uses (percentages as 0–100). */
final readonly class PricingRates
{
    public function __construct(
        public string $goldPct,
        public string $stonePct,
        public string $minimumEgp,
        public string $vatPct,
    ) {}
}
