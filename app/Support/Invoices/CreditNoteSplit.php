<?php

namespace App\Support\Invoices;

use App\Support\Pricing\Money;

/**
 * Split a credit note's gross into the commission and the VAT it reverses
 * (spec 016 FR-020): VAT = gross × rate / (100 + rate), rounded half-up to
 * 4 dp; the commission takes the rest, so net + VAT = gross exactly.
 */
final class CreditNoteSplit
{
    /** @return array{net: string, vat: string, gross: string} */
    public static function of(string $gross, string $vatRate): array
    {
        $gross = Money::fixed4($gross);
        $vat = Money::cmp($vatRate, '0') <= 0 ? '0.0000'
            : Money::round4(Money::div(Money::mul($gross, $vatRate), Money::add('100', $vatRate)));

        return ['net' => Money::fixed4(Money::sub($gross, $vat)), 'vat' => $vat, 'gross' => $gross];
    }
}
