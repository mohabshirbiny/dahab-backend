<?php

namespace App\Support\Pricing;

/**
 * The commission is VAT-inclusive (testing fixes, package C; docs/features/vat-inclusive-commission.md):
 * the seller is charged exactly the rate (or the minimum), and VAT is carved out of that total.
 * Owner's decision (2026-10-23): VAT is the rate applied to the total itself — 5 % of 39,000 =
 * 1,950 = commission 1,677 + VAT 273 — not total × rate / (100 + rate).
 *
 *   vat = round_half_up_4dp(total × vat_pct / 100),  net = total − vat
 *
 * The rounding residue stays inside the total, so net + vat = total exactly and the ledger
 * balances. `net` is what is posted to `dahab_commission`, `vat` to `vat_payable`. The one place
 * this split is made; a credit note gives back its invoice's own VAT share (CreditNoteSplit).
 */
final class VatSplit
{
    /** @return array{net: string, vat: string, total: string} */
    public static function fromInclusive(string $total, string $vatPct): array
    {
        $total = Money::fixed4($total);

        if (Money::cmp($vatPct, '0') <= 0 || Money::cmp($total, '0') === 0) {
            return ['net' => $total, 'vat' => '0.0000', 'total' => $total];
        }

        $vat = Money::round4(Money::div(Money::mul($total, $vatPct), '100'));

        return ['net' => Money::fixed4(Money::sub($total, $vat)), 'vat' => $vat, 'total' => $total];
    }
}
