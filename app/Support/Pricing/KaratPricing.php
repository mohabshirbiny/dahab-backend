<?php

namespace App\Support\Pricing;

/** What the calculator needs to know about a karat: its purity and its two adjustments. */
final readonly class KaratPricing
{
    public function __construct(
        public int $code,
        public string $purity,
        public Adjustment $buy,
        public Adjustment $sell,
    ) {}
}
