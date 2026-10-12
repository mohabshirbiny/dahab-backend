<?php

namespace App\Actions\MarketMakers;

use App\Support\Pricing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the market-maker programme gave up in a Cairo month (spec 023 research R17): the sum of the
 * figures stored on each order at settlement, never an estimate. An order of the month is one
 * *settled* in it whose waiver was a market-maker code. Market-maker sales settled before spec 023
 * have no stored figures: they count in `pieces_bought` and are said so in `not_recorded`.
 */
final class MarketMakerCostAction
{
    /** @return array<string, mixed> */
    public function handle(?string $month = null): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month ?? CarbonImmutable::now('Africa/Cairo')->format('Y-m'), 'Africa/Cairo');
        $end = $start->addMonth();

        $recorded = DB::table('order')->where('commission_waiver_kind', 'market_maker')
            ->where('settled_at', '>=', $start)->where('settled_at', '<', $end)
            ->selectRaw('count(*) as n, coalesce(sum(commission_given_up), 0) as c, coalesce(sum(spread_given_up), 0) as s, coalesce(sum(final_buyer_total), 0) as v')->first();

        // Settled before spec 023: a market-maker request, an order, a settlement, no stored figures.
        // The month is read from the use that made the request (the settlement had no timestamp).
        $notRecorded = (int) DB::table('order as o')
            ->join('buy_request as b', 'b.buy_request_id', '=', 'o.buy_request_id')
            ->whereNotNull('b.promo_code')->whereNotNull('o.settlement_txn_id')->whereNull('o.commission_ordinary_amount')
            ->where('o.accepted_at', '>=', $start)->where('o.accepted_at', '<', $end)->count();

        $commission = Money::fixed4((string) $recorded->c);
        $spread = Money::fixed4((string) $recorded->s);

        return [
            'month' => $start->format('Y-m'),
            'pieces_bought' => (int) $recorded->n + $notRecorded,
            'commission_given_up_egp' => $commission,
            'spread_given_up_egp' => $spread,
            'total_cost_egp' => Money::fixed4(Money::add($commission, $spread)),
            'not_recorded' => $notRecorded,
            // The spec 019 keys stay.
            'value_egp' => Money::fixed4((string) $recorded->v),
            'spread_paid_to_dealers_egp' => $spread,
            'commission_waived' => true,
        ];
    }
}
