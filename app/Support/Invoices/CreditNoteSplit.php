<?php

namespace App\Support\Invoices;

use App\Support\Pricing\Money;

/**
 * Split a credit note's gross into the commission and the VAT it reverses
 * (spec 016 FR-020): the invoice's own VAT share, VAT = gross × invoice VAT /
 * invoice gross, rounded half-up to 4 dp; the commission takes the rest, so
 * net + VAT = gross exactly. The note that clears what is left takes the VAT
 * not yet given back, so a fully credited invoice reverses its VAT to the piastre
 * whichever rule split it (VAT on top, carved out, or 14 % of the total).
 */
final class CreditNoteSplit
{
    /** @return array{net: string, vat: string, gross: string} */
    public static function of(string $gross, string $invoiceGross, string $invoiceVat, string $creditedGross = '0', string $creditedVat = '0'): array
    {
        $gross = Money::fixed4($gross);

        if (Money::cmp($invoiceVat, '0') <= 0 || Money::cmp($invoiceGross, '0') <= 0) {
            $vat = '0.0000';
        } elseif (Money::cmp(Money::add($creditedGross, $gross), $invoiceGross) >= 0) {
            $vat = Money::fixed4(Money::sub($invoiceVat, $creditedVat));
        } else {
            $vat = Money::round4(Money::div(Money::mul($gross, $invoiceVat), $invoiceGross));
        }

        return ['net' => Money::fixed4(Money::sub($gross, $vat)), 'vat' => $vat, 'gross' => $gross];
    }
}
