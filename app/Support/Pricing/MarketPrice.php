<?php

namespace App\Support\Pricing;

/** The provider's (or a manual) 24K bid and ask per gram, EGP (Part 4 §1). */
final readonly class MarketPrice
{
    public function __construct(
        public string $bid24k,
        public string $ask24k,
    ) {}
}
