<?php

namespace App\Support\Pricing;

/** A karat's market prices and its two published prices, per gram (Part 3 §2.2–2.3). */
final readonly class KaratPrices
{
    public function __construct(
        public int $code,
        public string $marketBid,
        public string $marketAsk,
        public string $mid,
        public string $sellersGet,
        public string $buyersPay,
        public string $difference,
        public bool $inverted,
    ) {}
}
