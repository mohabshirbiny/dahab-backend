<?php

namespace App\Support\Pricing;

/**
 * The commission is VAT-inclusive (testing fixes, package C; docs/features/vat-inclusive-commission.md):
 * the seller is charged exactly the rate (or the minimum), and VAT is carved out of that total.
 *
 *   net = round_half_up_4dp(total × 100 / (100 + vat_pct)),  vat = total − net
 *
 * The rounding residue stays inside the total, so net + vat = total exactly and the ledger
 * balances. `net` is what is posted to `dahab_commission`, `vat` to `vat_payable`. The one place
 * this split is made; credit notes use CreditNoteSplit (VAT rounded first), which gives the same
 * pair at a 14% rate (see VatSplitTest).
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

        $net = Money::round4(Money::div(Money::mul($total, '100'), Money::add('100', $vatPct)));

        return ['net' => $net, 'vat' => Money::fixed4(Money::sub($total, $net)), 'total' => $total];
    }
}
