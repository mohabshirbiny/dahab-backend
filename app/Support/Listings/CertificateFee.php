<?php

namespace App\Support\Listings;

use App\Enums\CertificateKind;
use App\Enums\PieceCategory;
use App\Models\Lab;
use App\Models\Listing;
use App\Support\Pricing\Money;

/**
 * What the lab that inspects a piece costs its seller (docs/features/labs-and-certificate-costs.md).
 * A stone piece pays by its certificate: from this lab, from any other, or none. Gold pays the gold
 * inspection cost, and a cost of 0 is no cost at all. It comes out of the seller's proceeds and never
 * changes what the buyer pays. A free relist, or a stone piece that names no certificate, pays nothing.
 */
final class CertificateFee
{
    /** The cost for a certificate at a lab, 4-dp; 0 when no certificate is named. */
    public function forKind(?CertificateKind $kind, ?int $certificateLabId = null, ?Lab $lab = null): string
    {
        if ($kind === null) {
            return '0.0000';
        }
        $lab ??= Lab::main();

        return Money::fixed4((string) match ($kind) {
            CertificateKind::LAB => ($certificateLabId ?? Lab::main()->lab_id) === $lab->lab_id ? $lab->certificate_own_cost_egp : $lab->certificate_other_cost_egp,
            CertificateKind::OTHER_LAB => $lab->certificate_other_cost_egp,
            CertificateKind::NONE => $lab->certificate_none_cost_egp,
        });
    }

    /** The gold inspection cost of a lab, or null when it is 0: then it appears nowhere. */
    public function goldCost(?Lab $lab = null): ?string
    {
        $cost = Money::fixed4((string) ($lab ?? Lab::main())->gold_inspection_cost_egp);

        return Money::cmp($cost, '0') > 0 ? $cost : null;
    }

    /** The certificate cost of a listing, or null when the piece carries no certificate. */
    public function forListing(Listing $listing, ?Lab $lab = null): ?string
    {
        return $listing->certificate_kind === null || ! CertificateKind::appliesTo($listing->category)
            ? null
            : $this->forKind($listing->certificate_kind, $listing->certificate_lab_id, $lab ?? $listing->lab);
    }

    /** The gold inspection cost of a listing: gold only, never a free relist. */
    public function goldForListing(Listing $listing, ?Lab $lab = null): ?string
    {
        return $listing->category !== PieceCategory::GOLD || $listing->isFreeRelist()
            ? null
            : $this->goldCost($lab ?? $listing->lab);
    }

    /** Proceeds less the fee (never below zero for a display figure). */
    public static function net(string $proceeds, ?string $fee): string
    {
        if ($fee === null) {
            return $proceeds;
        }
        $net = Money::sub($proceeds, $fee);

        return Money::cmp($net, '0') < 0 ? '0.0000' : Money::fixed4($net);
    }
}
