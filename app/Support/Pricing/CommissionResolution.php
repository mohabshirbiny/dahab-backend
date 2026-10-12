<?php

namespace App\Support\Pricing;

use App\Enums\WaiverKind;

/**
 * What the settlement charges and which waiver paid for the difference (spec 023 research R3, R4,
 * R17). `ordinary` is what an ordinary sale of the same piece pays; `charged` what this one pays.
 */
final readonly class CommissionResolution
{
    public function __construct(
        public WaiverKind $kind,
        public ?string $creditId,
        public string $ordinary,
        public string $charged,
        public string $vat,
        public string $givenUp,
        public string $floor,
        public bool $floorApplied,
    ) {}

    /** The breakdown with the commission, its VAT and the seller's proceeds re-derived from `charged`. */
    public function applyTo(PriceBreakdown $b): PriceBreakdown
    {
        return new PriceBreakdown(
            buyerTotal: $b->buyerTotal,
            sellerGross: $b->sellerGross,
            spread: $b->spread,
            commission: $this->charged,
            vat: $this->vat,
            sellerProceeds: Money::fixed4(Money::sub(Money::sub($b->sellerGross, $this->charged), $this->vat)),
            protectedGoldValue: $b->protectedGoldValue,
            karatPrices: $b->karatPrices,
        );
    }

    public function isCredit(): bool
    {
        return $this->kind === WaiverKind::CREDIT;
    }
}
