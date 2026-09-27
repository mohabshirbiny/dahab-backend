<?php

namespace App\Support\Pricing;

/**
 * One piece's figures (Part 3 §2). `spread` and `sellerProceeds` are derived
 * by subtraction, so buyerTotal − sellerProceeds = commission + vat + spread
 * holds exactly.
 */
final readonly class PriceBreakdown
{
    public function __construct(
        public string $buyerTotal,
        public string $sellerGross,
        public string $spread,
        public string $commission,
        public string $vat,
        public string $sellerProceeds,
        public ?string $protectedGoldValue,
        public ?KaratPrices $karatPrices,
    ) {}
}
