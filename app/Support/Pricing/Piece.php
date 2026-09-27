<?php

namespace App\Support\Pricing;

use App\Enums\PieceCategory;

/** The piece being priced: gold by weight and making charge; stones by asking price. */
final readonly class Piece
{
    private function __construct(
        public PieceCategory $category,
        public ?KaratPricing $karat,
        public ?string $weight,
        public ?string $makingPerGram,
        public ?string $askingPrice,
        public bool $commissionWaived,
    ) {}

    public static function gold(KaratPricing $karat, string $weight, string $makingPerGram, bool $commissionWaived = false): self
    {
        return new self(PieceCategory::GOLD, $karat, $weight, $makingPerGram, null, $commissionWaived);
    }

    public static function diamond(string $askingPrice, bool $commissionWaived = false): self
    {
        return new self(PieceCategory::DIAMOND, null, null, null, $askingPrice, $commissionWaived);
    }

    public static function goldWithDiamond(KaratPricing $karat, string $weight, string $askingPrice, bool $commissionWaived = false): self
    {
        return new self(PieceCategory::GOLD_WITH_DIAMOND, $karat, $weight, null, $askingPrice, $commissionWaived);
    }
}
