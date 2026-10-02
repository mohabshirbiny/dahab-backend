<?php

namespace App\Support\Orders;

/**
 * One order's settlement (spec 012 research R6–R7; Part 3 §3): what the
 * buyer pays in total, what the seller receives, Dahab's three lines, and how
 * the buyer's deposit and balance cover the total. Every figure is a 4-dp
 * string; `spread` may be negative.
 */
final readonly class SettlementFigures
{
    public function __construct(
        public string $weight,
        public string $buyerTotal,
        public string $sellerGross,
        public string $commission,
        public string $vat,
        public string $spread,
        public string $proceeds,
        public string $deposit,
        public string $balance,
        public string $excess,
    ) {}

    /** @return array<string, string|null> the "order" columns */
    public function columns(): array
    {
        return [
            'final_weight_g' => $this->weight === '' ? null : $this->weight,
            'final_buyer_total' => $this->buyerTotal,
            'final_seller_gross' => $this->sellerGross,
            'commission_amount' => $this->commission,
            'vat_amount' => $this->vat,
            'spread_amount' => $this->spread,
            'seller_proceeds' => $this->proceeds,
            'balance_amount' => $this->balance,
        ];
    }
}
