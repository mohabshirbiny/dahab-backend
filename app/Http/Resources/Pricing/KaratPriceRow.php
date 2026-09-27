<?php

namespace App\Http\Resources\Pricing;

use App\Models\Karat;
use App\Support\Pricing\KaratPrices;
use App\Support\Pricing\KaratPricing;
use OpenApi\Attributes as OA;

/** One karat's prices as the Dashboard shows them (spec 005 FR-030). */
#[OA\Schema(
    schema: 'DashboardKaratPrice',
    required: ['code', 'purity', 'is_enabled', 'market_bid', 'market_ask', 'sellers_get', 'buyers_pay', 'difference', 'adjustments', 'inverted'],
    properties: [
        new OA\Property(property: 'code', type: 'integer', example: 21),
        new OA\Property(property: 'purity', type: 'string', example: '0.87500'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'market_bid', type: 'string', description: '24K bid × purity ÷ 0.999'),
        new OA\Property(property: 'market_ask', type: 'string'),
        new OA\Property(property: 'sellers_get', type: 'string', description: 'Market bid with the buy-side adjustment'),
        new OA\Property(property: 'buyers_pay', type: 'string', description: 'Market ask with the sell-side adjustment'),
        new OA\Property(property: 'difference', type: 'string', description: 'buyers_pay − sellers_get, per gram'),
        new OA\Property(property: 'adjustments', properties: [
            new OA\Property(property: 'buy', ref: '#/components/schemas/DashboardAdjustment'),
            new OA\Property(property: 'sell', ref: '#/components/schemas/DashboardAdjustment'),
        ], type: 'object'),
        new OA\Property(property: 'inverted', type: 'boolean', description: 'True when this karat cannot be quoted'),
    ],
)]
final class KaratPriceRow
{
    /** @return array<string, mixed> */
    public static function of(Karat $karat, KaratPricing $pricing, KaratPrices $prices): array
    {
        return [
            'code' => $karat->karat_code,
            'purity' => (string) $karat->purity_ratio,
            'is_enabled' => (bool) $karat->is_enabled,
            'market_bid' => $prices->marketBid,
            'market_ask' => $prices->marketAsk,
            'sellers_get' => $prices->sellersGet,
            'buyers_pay' => $prices->buyersPay,
            'difference' => $prices->difference,
            'adjustments' => self::adjustments($pricing),
            'inverted' => $prices->inverted,
        ];
    }

    /** @return array{buy: array{kind: string, value: string}, sell: array{kind: string, value: string}} */
    public static function adjustments(KaratPricing $pricing): array
    {
        return [
            'buy' => ['kind' => $pricing->buy->kind->value, 'value' => bcadd($pricing->buy->value, '0', 4)],
            'sell' => ['kind' => $pricing->sell->kind->value, 'value' => bcadd($pricing->sell->value, '0', 4)],
        ];
    }
}
