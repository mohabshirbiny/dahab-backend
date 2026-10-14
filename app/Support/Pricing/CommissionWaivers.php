<?php

namespace App\Support\Pricing;

use App\Enums\CreditKind;
use App\Enums\CreditSource;
use App\Enums\WaiverKind;

/**
 * The one place that decides the commission of a sale when more than one thing could lower it
 * (spec 023 research R3, R4): the lowest single commission among
 *
 *   none · free relist (0) · market-maker code (0) · one credit (waive 0 / percent / amount)
 *
 * never a sum. The minimum commission floors a `percent` or `amount` credit; a waiver of the
 * whole commission (free relist, market maker, `waive`) is not floored (TS §2.5). A credit that
 * cannot lower the ordinary commission is not chosen, so it is not spent. A tie goes to the
 * waiver that costs the customer least to keep: free relist, market maker, a code's credit,
 * a referral credit; among credits the earliest expiry, then the oldest.
 *
 * Pure: no database, no clock. The settlement, the advance's provisional proceeds and the sell
 * form's preview all call it with the same inputs, so they agree.
 *
 * Package C (VAT-inclusive commission): every option is compared as the TOTAL the seller pays, VAT
 * included — the ordinary total, the minimum (VAT-inclusive too), a percent or an amount off the
 * total — and only the winner is split into net + VAT (VatSplit). The resolution keeps its net
 * figures (`ordinary`, `charged`, `givenUp`, `floor`: what the order columns and the DH026 check
 * store) and adds the totals (`ordinaryTotal`, `chargedTotal`, `givenUpTotal`) for the customer's
 * figures and for spending an amount credit.
 */
final class CommissionWaivers
{
    private const PRIORITY_FREE_RELIST = 0;

    private const PRIORITY_MARKET_MAKER = 1;

    private const PRIORITY_CODE_CREDIT = 2;

    private const PRIORITY_REFERRAL_CREDIT = 3;

    private const PRIORITY_NONE = 9;

    /**
     * @param  string  $ordinary  what an ordinary sale charges the seller, VAT included (calculator, no waiver), 4 dp
     * @param  list<CreditCandidate>  $credits  the seller's usable credits (active, unexpired, bound to this listing or unbound)
     */
    public function resolve(string $ordinary, PricingRates $rates, bool $freeRelist, bool $marketMaker, array $credits): CommissionResolution
    {
        $floor = Money::fixed4($rates->minimumEgp);
        $ordinary = Money::round4($ordinary);

        // [commission, kind, creditId, priority, floorApplied, expiresAt, grantedAt, id]
        $options = [[$ordinary, WaiverKind::NONE, null, self::PRIORITY_NONE, true, null, '', '']];

        if ($freeRelist) {
            $options[] = ['0.0000', WaiverKind::FREE_RELIST, null, self::PRIORITY_FREE_RELIST, false, null, '', ''];
        }
        if ($marketMaker) {
            $options[] = ['0.0000', WaiverKind::MARKET_MAKER, null, self::PRIORITY_MARKET_MAKER, false, null, '', ''];
        }

        foreach ($credits as $credit) {
            $charged = match ($credit->kind) {
                CreditKind::WAIVE => '0.0000',
                CreditKind::PERCENT => Money::max(
                    Money::round4(Money::percent($ordinary, Money::sub('100', (string) $credit->percentOff))),
                    $floor,
                ),
                CreditKind::AMOUNT => Money::max(
                    Money::fixed4(Money::sub($ordinary, (string) $credit->amountLeft)),
                    $floor,
                ),
            };
            $charged = Money::fixed4($charged);

            // A credit that gives nothing is not chosen, so it is not spent.
            if (Money::cmp($charged, $ordinary) >= 0) {
                continue;
            }

            $options[] = [
                $charged,
                WaiverKind::CREDIT,
                $credit->id,
                $credit->source === CreditSource::PROMO_CODE ? self::PRIORITY_CODE_CREDIT : self::PRIORITY_REFERRAL_CREDIT,
                $credit->kind !== CreditKind::WAIVE,
                $credit->expiresAt,
                $credit->grantedAt,
                $credit->id,
            ];
        }

        usort($options, function (array $a, array $b): int {
            return Money::cmp($a[0], $b[0])
                ?: $a[3] <=> $b[3]
                // Earliest expiry first; a credit that never expires comes after one that does.
                ?: (($a[5] ?? '9999') <=> ($b[5] ?? '9999'))
                ?: $a[6] <=> $b[6]
                ?: $a[7] <=> $b[7];
        });

        [$charged, $kind, $creditId, , $floorApplied] = $options[0];
        $charged = Money::fixed4($charged);

        $chargedSplit = VatSplit::fromInclusive($charged, $rates->vatPct);
        $ordinaryNet = VatSplit::fromInclusive($ordinary, $rates->vatPct)['net'];

        return new CommissionResolution(
            kind: $kind,
            creditId: $creditId,
            ordinary: $ordinaryNet,
            charged: $chargedSplit['net'],
            vat: $chargedSplit['vat'],
            givenUp: Money::fixed4(Money::sub($ordinaryNet, $chargedSplit['net'])),
            floor: VatSplit::fromInclusive($floor, $rates->vatPct)['net'],
            floorApplied: $floorApplied,
            ordinaryTotal: $ordinary,
            chargedTotal: $charged,
            givenUpTotal: Money::fixed4(Money::sub($ordinary, $charged)),
        );
    }
}
