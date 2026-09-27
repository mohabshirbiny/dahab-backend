<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Pricing\ConfirmManualPriceAction;
use App\Actions\Pricing\EnterManualPriceAction;
use App\Enums\AdjustmentKind;
use App\Enums\ManualPriceStatus;
use App\Exceptions\DomainApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Pricing\EnterManualPriceRequest;
use App\Http\Requests\Dashboard\Pricing\PreviewPricesRequest;
use App\Http\Resources\Pricing\GoldPriceResource;
use App\Http\Resources\Pricing\KaratPriceRow;
use App\Http\Resources\Pricing\ManualGoldPriceResource;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\PriceFeedStatus;
use App\Support\Pricing\Adjustment;
use App\Support\Pricing\FeedHealth;
use App\Support\Pricing\MarketPrice;
use App\Support\Pricing\Money;
use App\Support\Pricing\PricingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Gold prices (spec 005): the current prices per karat, their history, a
 * server-side preview, and the manual price with its confirmation.
 */
#[OA\Tag(name: 'Dashboard Pricing', description: 'Dashboard API — gold prices, per-karat adjustments and settings (spec 005). Viewing: pricing.view.')]
class GoldPriceController extends Controller
{
    #[OA\Get(
        path: '/dashboard/gold-prices/current',
        operationId: 'dashboardCurrentGoldPrices',
        summary: 'The current price, feed health, any pending manual price, and every karat’s prices',
        description: 'karats is empty until the first price is recorded. Disabled karats are priced too. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        responses: [
            new OA\Response(response: 200, description: 'Current prices', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'price', ref: '#/components/schemas/DashboardGoldPrice', nullable: true),
                    new OA\Property(property: 'feed', properties: [
                        new OA\Property(property: 'state', type: 'string', enum: ['healthy', 'down', 'not_configured']),
                        new OA\Property(property: 'last_success_at', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'last_failure_at', type: 'string', format: 'date-time', nullable: true),
                    ], type: 'object'),
                    new OA\Property(property: 'pending', ref: '#/components/schemas/DashboardManualPrice', nullable: true),
                    new OA\Property(property: 'karats', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardKaratPrice')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function current(PricingContext $context, FeedHealth $feed): JsonResponse
    {
        $price = $context->currentPrice();
        $status = PriceFeedStatus::query()->find(PriceFeedStatus::PROVIDER);
        $pending = ManualGoldPrice::query()->where('status', ManualPriceStatus::PENDING)->first();
        $market = $context->market($price);

        return response()->json(['data' => [
            'price' => $price === null ? null : GoldPriceResource::make($price)->resolve(),
            'feed' => [
                'state' => $feed->state(),
                'last_success_at' => $status?->last_success_at?->toIso8601String(),
                'last_failure_at' => $status?->last_failure_at?->toIso8601String(),
            ],
            'pending' => $pending !== null && $pending->isConfirmable() ? ManualGoldPriceResource::make($pending)->resolve() : null,
            'karats' => $market === null ? [] : $context->karatPrices($market)
                ->map(fn (array $row) => KaratPriceRow::of($row['karat'], $row['pricing'], $row['prices']))->values()->all(),
        ]]);
    }

    #[OA\Get(
        path: '/dashboard/gold-prices',
        operationId: 'dashboardGoldPriceHistory',
        summary: 'Prices that took effect, newest first',
        description: 'Feed and manual. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Prices', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardGoldPrice')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']])['per_page'] ?? 25;

        return GoldPriceResource::collection(
            GoldPrice::query()->with(['manual.enteredBy', 'manual.confirmedBy'])->latestFirst()->paginate($perPage),
        );
    }

    #[OA\Post(
        path: '/dashboard/gold-prices/preview',
        operationId: 'dashboardPreviewGoldPrices',
        summary: 'Preview every karat’s prices for a price and/or adjustments, without saving',
        description: 'Both 24K prices, a change_pct from the current pair, or neither (the current price). Optional adjustments per karat code replace the saved ones in the preview. Writes nothing. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/DashboardPreviewPrices')),
        responses: [
            new OA\Response(response: 200, description: 'Preview', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'bid_24k', type: 'string'),
                    new OA\Property(property: 'ask_24k', type: 'string'),
                    new OA\Property(property: 'deviation_pct', type: 'string', nullable: true),
                    new OA\Property(property: 'karats', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardKaratPrice')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'no_gold_price', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function preview(PreviewPricesRequest $request, PricingContext $context): JsonResponse
    {
        $current = $context->market();
        $bid = $request->validated('bid_24k');
        $pct = $request->validated('change_pct');

        $market = match (true) {
            $bid !== null => new MarketPrice(Money::round4((string) $bid), Money::round4((string) $request->validated('ask_24k'))),
            $pct !== null => $current === null ? throw DomainApiException::noGoldPrice() : new MarketPrice(
                Money::round4(Money::add($current->bid24k, Money::percent($current->bid24k, (string) $pct))),
                Money::round4(Money::add($current->ask24k, Money::percent($current->ask24k, (string) $pct))),
            ),
            default => $current ?? throw DomainApiException::noGoldPrice(),
        };

        $overrides = collect($request->validated('adjustments', []))->map(fn (array $sides) => collect($sides)
            ->map(fn (array $a) => new Adjustment(AdjustmentKind::from($a['kind']), (string) $a['value']))->all()
        )->all();

        $deviation = $current === null ? null : Money::round4(Money::max(
            self::deviation($current->bid24k, $market->bid24k),
            self::deviation($current->ask24k, $market->ask24k),
        ));

        return response()->json(['data' => [
            'bid_24k' => $market->bid24k,
            'ask_24k' => $market->ask24k,
            'deviation_pct' => $deviation,
            'karats' => $context->karatPrices($market, $overrides)
                ->map(fn (array $row) => KaratPriceRow::of($row['karat'], $row['pricing'], $row['prices']))->values()->all(),
        ]]);
    }

    private static function deviation(string $old, string $new): string
    {
        $diff = Money::sub($new, $old);

        return Money::div(Money::mul(Money::cmp($diff, '0') < 0 ? Money::sub('0', $diff) : $diff, '100'), $old);
    }

    #[OA\Post(
        path: '/dashboard/gold-prices/manual',
        operationId: 'dashboardEnterManualPrice',
        summary: 'Enter the 24K bid and ask by hand while the feed is down',
        description: 'Accepted only while the price feed is down or not configured. Takes effect at once (201) when there is no price yet or the deviation is within manualprice.confirm_deviation_pct; otherwise it is recorded as pending (202, code manual_price_confirm_required) until confirmed. A newer entry supersedes a pending one. Audited (pricing.manual_price.entered). Requires gold_price.enter.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardEnterManualPrice')),
        responses: [
            new OA\Response(response: 201, description: 'Effective', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardManualPrice'),
            ])),
            new OA\Response(response: 202, description: 'Pending confirmation', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardManualPrice'),
                new OA\Property(property: 'code', type: 'string', example: 'manual_price_confirm_required'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'price_feed_healthy | no_gold_price (change_pct with no price)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function manual(EnterManualPriceRequest $request, EnterManualPriceAction $enter): JsonResponse
    {
        $manual = $enter->handle(
            $request->user('staff'),
            self::decimal($request->validated('bid_24k')),
            self::decimal($request->validated('ask_24k')),
            self::decimal($request->validated('change_pct')),
            $request->validated('reason'),
        );

        $response = ManualGoldPriceResource::make($manual)->response();

        return $manual->status === ManualPriceStatus::PENDING
            ? $response->setStatusCode(202)->setData(array_merge($response->getData(true), ['code' => 'manual_price_confirm_required']))
            : $response->setStatusCode(201);
    }

    #[OA\Post(
        path: '/dashboard/gold-prices/manual/{manualPrice}/confirm',
        operationId: 'dashboardConfirmManualPrice',
        summary: 'Confirm a pending manual price',
        description: 'Only the latest pending request, within manualprice.pending_expiry_hours, and only if no other price took effect since it was entered. When manualprice.confirmer_must_differ is on, the person who entered it cannot confirm it. Audited (pricing.manual_price.confirmed). Requires gold_price.confirm.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [new OA\Parameter(name: 'manualPrice', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Now effective', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardManualPrice'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | confirmer_must_differ | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'manual_price_not_pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function confirm(Request $request, int $manualPrice, ConfirmManualPriceAction $confirm): ManualGoldPriceResource
    {
        return ManualGoldPriceResource::make($confirm->handle($request->user('staff'), $manualPrice));
    }

    /** Request numbers arrive as int/float/string; keep them as decimal strings. */
    private static function decimal(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
