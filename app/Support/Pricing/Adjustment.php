<?php

namespace App\Support\Pricing;

use App\Enums\AdjustmentKind;

/** One side's adjustment: EGP per gram added, or a percentage (spec 005). */
final readonly class Adjustment
{
    public function __construct(
        public AdjustmentKind $kind,
        public string $value,
    ) {}

    public function apply(string $price): string
    {
        return match ($this->kind) {
            AdjustmentKind::FIXED => Money::add($price, $this->value),
            AdjustmentKind::PERCENT => Money::add($price, Money::percent($price, $this->value)),
        };
    }
}
