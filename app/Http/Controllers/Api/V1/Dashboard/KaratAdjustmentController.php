<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Pricing\ChangeKaratAdjustmentsAction;
use App\Enums\AdjustmentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Pricing\UpdateKaratAdjustmentsRequest;
use App\Http\Resources\Pricing\AdjustmentChangeResource;
use App\Http\Resources\Pricing\KaratPriceRow;
use App\Models\KaratPriceAdjustmentHistory;
use App\Support\Pricing\Adjustment;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/** Per-karat buy/sell price adjustments (spec 005 US3, FR-017). */
class KaratAdjustmentController extends Controller
{
    #[OA\Put(
        path: '/dashboard/karats/{code}/adjustments',
        operationId: 'dashboardUpdateKaratAdjustments',
        summary: "Replace a karat's buy-side and sell-side adjustments",
        description: 'Each side is fixed (EGP per gram) or percent. Refused (422 price_inverted) when, at the current price, buyers would pay less than sellers get or a price would be zero or less. A reason is required. Each changed side is kept in history and audited (pricing.adjustment.changed). Requires pricing.rates.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateKaratAdjustments')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'code', type: 'integer'),
                    new OA\Property(property: 'adjustments', type: 'object'),
                    new OA\Property(property: 'sellers_get', type: 'string', nullable: true),
                    new OA\Property(property: 'buyers_pay', type: 'string', nullable: true),
                    new OA\Property(property: 'difference', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required | price_inverted', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateKaratAdjustmentsRequest $request, int $code, ChangeKaratAdjustmentsAction $change, PricingContext $context, PriceCalculator $calculator): JsonResponse
    {
        $side = fn (string $name) => new Adjustment(
            AdjustmentKind::from($request->validated("{$name}.kind")),
            (string) $request->validated("{$name}.value"),
        );

        $pricing = $change->handle($request->user('staff'), $code, $side('buy'), $side('sell'), $request->validated('reason'));
        $market = $context->market();
        $prices = $market === null ? null : $calculator->karatPrices($market, $pricing);

        return response()->json(['data' => [
            'code' => $pricing->code,
            'adjustments' => KaratPriceRow::adjustments($pricing),
            'sellers_get' => $prices?->sellersGet,
            'buyers_pay' => $prices?->buyersPay,
            'difference' => $prices?->difference,
        ]]);
    }

    #[OA\Get(
        path: '/dashboard/price-adjustments/history',
        operationId: 'dashboardAdjustmentHistory',
        summary: 'Adjustment changes, newest first',
        description: 'Optionally for one karat. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [
            new OA\Parameter(name: 'karat', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Changes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardAdjustmentChange')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function history(Request $request): AnonymousResourceCollection
    {
        $query = $request->validate([
            'karat' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        return AdjustmentChangeResource::collection(
            KaratPriceAdjustmentHistory::query()->with('changedBy')
                ->when($query['karat'] ?? null, fn ($q, $karat) => $q->where('karat_code', $karat))
                ->orderByDesc('changed_at')->orderByDesc('history_id')
                ->paginate($query['per_page'] ?? 25),
        );
    }
}
