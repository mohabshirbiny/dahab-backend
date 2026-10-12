<?php

namespace App\Support\Orders;

use App\Enums\WaiverKind;

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
        // Spec 023 R17: what an ordinary sale would have paid, what this one gave up, and why.
        public string $ordinaryCommission = '0.0000',
        public string $commissionGivenUp = '0.0000',
        public WaiverKind $waiverKind = WaiverKind::NONE,
        public ?string $waiverCreditId = null,
        public string $floor = '0.0000',
        public bool $floorApplied = true,
        public string $spreadGivenUp = '0.0000',
    ) {}

    /** @return array<string, mixed> the "order" columns */
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
            // The cost figures, stored with the settlement (spec 023 R17, R4).
            'commission_ordinary_amount' => $this->ordinaryCommission,
            'commission_given_up' => $this->commissionGivenUp,
            'commission_waiver_kind' => $this->waiverKind->value,
            'commission_waiver_credit_id' => $this->waiverCreditId,
            'commission_floor_egp' => $this->floor,
            'commission_floor_applied' => $this->floorApplied,
            'spread_given_up' => $this->spreadGivenUp,
            'settled_at' => now(),
        ];
    }
}
