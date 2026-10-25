<?php

namespace App\Support\Orders;

use App\Enums\WaiverKind;
use App\Support\Pricing\Money;

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
        // Package C: the saving VAT included (what an amount credit is spent by); not stored on the order.
        public string $commissionGivenUpTotal = '0.0000',
        // Package D: the IGI certificate cost taken from the seller's proceeds (already out of `proceeds`); locked on the order at acceptance.
        public string $certificateFee = '0.0000',
        // The ordinary commission VAT included, stored so the seller's saving can be shown whole.
        public string $ordinaryCommissionTotal = '0.0000',
        // A buyer code's discount off the price (2026-10-26), and what pays for it: commission | spread.
        public string $priceDiscount = '0.0000',
        public ?string $priceDiscountFundedBy = null,
    ) {}

    /** Everything a buyer's code takes off the total: the spread handed back and a price discount. */
    public function buyerDiscount(): string
    {
        return Money::fixed4(Money::add($this->spreadGivenUp, $this->priceDiscount));
    }

    /** What the buyer pays for the piece: the total less what their code took off. */
    public function buyerPays(): string
    {
        return Money::fixed4(Money::sub($this->buyerTotal, $this->buyerDiscount()));
    }

    /** What leaves the buyer's wallet at payment: the balance less what the code took off, never below 0. */
    public function cashDue(): string
    {
        $due = Money::sub($this->balance, $this->buyerDiscount());

        return Money::cmp($due, '0') > 0 ? Money::fixed4($due) : '0.0000';
    }

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
            'commission_ordinary_total' => $this->ordinaryCommissionTotal,
            'price_discount_egp' => $this->priceDiscount,
            'price_discount_funded_by' => Money::cmp($this->priceDiscount, '0') > 0 ? $this->priceDiscountFundedBy : null,
            'settled_at' => now(),
        ];
    }
}
