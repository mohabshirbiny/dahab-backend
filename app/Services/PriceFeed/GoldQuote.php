<?php

namespace App\Services\PriceFeed;

/** One good reading: the 24K bid and ask per gram, EGP, as 4-dp strings. */
final readonly class GoldQuote
{
    public function __construct(
        public string $bid24k,
        public string $ask24k,
    ) {}
}
