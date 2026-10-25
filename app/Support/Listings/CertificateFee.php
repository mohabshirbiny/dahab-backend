<?php

namespace App\Support\Listings;

use App\Enums\CertificateKind;
use App\Models\Listing;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;

/**
 * What IGI's certificate costs the seller of a stone piece (Part 3 §5 / OI-3.2): the setting for
 * the kind of certificate the listing names. It comes out of the seller's proceeds and never
 * changes what the buyer pays. A listing that names none (gold, or listed before this existed)
 * pays nothing.
 */
final class CertificateFee
{
    public function __construct(private readonly Settings $settings) {}

    /** The cost for a kind, 4-dp. */
    public function forKind(?CertificateKind $kind): string
    {
        return $kind === null ? '0.0000' : Money::fixed4($this->settings->numeric($kind->costSetting()));
    }

    /** The fee shown on a quote, or null when the piece carries no certificate. */
    public function forListing(Listing $listing): ?string
    {
        return $listing->certificate_kind === null || ! CertificateKind::appliesTo($listing->category)
            ? null
            : $this->forKind($listing->certificate_kind);
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
