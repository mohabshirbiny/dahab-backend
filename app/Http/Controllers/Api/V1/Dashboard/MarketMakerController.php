<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\MarketMakers\ApproveForMarketMakersAction;
use App\Actions\MarketMakers\CreatePromoCodeAction;
use App\Actions\MarketMakers\ListMarketMakerQueueAction;
use App\Actions\MarketMakers\UpdatePromoCodeAction;
use App\Http\Controllers\Controller;
use App\Models\MarketMakerApproval;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The market-maker programme, staff side (spec 019 US6 / 6A): codes (`promo.manage`), the
 * approval queue and approvals (`market_maker.approve`), the use log and the cost panel (either).
 * Every write is idempotent and audited; nothing here moves money.
 */
class MarketMakerController extends Controller
{
    #[OA\Get(
        path: '/dashboard/promo-codes',
        operationId: 'dashboardPromoCodes',
        summary: 'Market-maker codes',
        description: 'Spec 019 FR-060. Each code with its account, monthly cap, whether it is on, and its uses this month. Requires promo.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 200, description: 'Codes'), new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function codes(): JsonResponse
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $uses = PromoCodeUse::query()->where('allowed', true)->where('used_at', '>=', $monthStart)
            ->selectRaw('code, count(*) as n')->groupBy('code')->pluck('n', 'code');

        $rows = PromoCode::query()->with('customer:customer_id,display_ref,full_name')->orderByDesc('created_at')->get()
            ->map(fn (PromoCode $c) => [
                'code' => $c->code,
                'kind' => $c->kind,
                'customer' => $c->customer === null ? null : ['id' => $c->customer->customer_id, 'display_ref' => $c->customer->display_ref, 'full_name' => $c->customer->full_name],
                'monthly_cap_egp' => $c->monthly_cap_egp,
                'is_active' => $c->is_active,
                'commission_waived' => $c->commission_waived,
                'gives_spread' => $c->gives_spread,
                'uses_this_month' => (int) ($uses[$c->code] ?? 0),
                'created_at' => $c->created_at->toIso8601String(),
                'deactivated_at' => $c->deactivated_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    #[OA\Post(
        path: '/dashboard/promo-codes',
        operationId: 'dashboardCreatePromoCode',
        summary: 'Create a market-maker code',
        description: 'Spec 019 FR-060. `kind` must be market_maker (promo_kind_not_available otherwise); the customer must be of type market_maker (promo_customer_not_market_maker). Audited (promo.created). Requires promo.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 409, description: 'promo_code_exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')), new OA\Response(response: 422, description: 'promo_kind_not_available | promo_customer_not_market_maker | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function create(Request $request, CreatePromoCodeAction $create): JsonResponse
    {
        $v = $request->validate([
            'kind' => ['sometimes', 'string', 'in:first_sale,market_maker'],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{3,32}$/'],
            'tied_customer_id' => ['required', 'uuid'],
            'monthly_cap_egp' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $promo = $create->handle(
            $request->user('staff'),
            $v['kind'] ?? 'market_maker',
            $v['code'],
            $v['tied_customer_id'],
            isset($v['monthly_cap_egp']) ? (string) $v['monthly_cap_egp'] : null,
            $request->attributes->get('context'),
        );

        return response()->json(['data' => ['code' => $promo->code, 'is_active' => $promo->is_active, 'monthly_cap_egp' => $promo->monthly_cap_egp]], 201);
    }

    #[OA\Patch(
        path: '/dashboard/promo-codes/{code}',
        operationId: 'dashboardUpdatePromoCode',
        summary: 'Change a code\'s cap or turn it on or off',
        description: 'Spec 019 FR-060. Only `monthly_cap_egp` and `active` change. Audited (promo.updated). Requires promo.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Saved'), new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function update(Request $request, string $code, UpdatePromoCodeAction $update): JsonResponse
    {
        $v = $request->validate([
            'monthly_cap_egp' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $changes = [];
        if (array_key_exists('monthly_cap_egp', $v)) {
            $changes['monthly_cap_egp'] = $v['monthly_cap_egp'] === null ? null : (string) $v['monthly_cap_egp'];
        }
        if (array_key_exists('active', $v)) {
            $changes['active'] = (bool) $v['active'];
        }

        $promo = $update->handle($request->user('staff'), strtoupper($code), $changes, $request->attributes->get('context'));

        return response()->json(['data' => ['code' => $promo->code, 'is_active' => $promo->is_active, 'monthly_cap_egp' => $promo->monthly_cap_egp]]);
    }

    #[OA\Get(
        path: '/dashboard/promo-codes/{code}/uses',
        operationId: 'dashboardPromoCodeUses',
        summary: 'Every use of a code',
        description: 'Spec 019 FR-061. Allowed and blocked uses, newest first, with the reason a use was blocked. Requires promo.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Uses'), new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function uses(string $code): JsonResponse
    {
        $promo = PromoCode::query()->whereKey(strtoupper($code))->firstOrFail();

        $rows = PromoCodeUse::query()->where('code', $promo->code)->with('customer:customer_id,display_ref,full_name')
            ->orderByDesc('used_at')->limit(500)->get()
            ->map(fn (PromoCodeUse $u) => [
                'used_at' => $u->used_at->toIso8601String(),
                'customer' => ['display_ref' => $u->customer?->display_ref, 'full_name' => $u->customer?->full_name],
                'allowed' => $u->allowed,
                'block_reason' => $u->block_reason,
                'order_id' => $u->order_id,
            ])->values();

        return response()->json(['data' => $rows]);
    }

    #[OA\Get(
        path: '/dashboard/market-maker/queue',
        operationId: 'dashboardMarketMakerQueue',
        summary: 'Pieces waiting for approval',
        description: 'Spec 019 FR-062. Live pieces listed for at least marketmaker.min_list_age_days and not yet approved, with the numbers to weigh. Views are not measured (null). Requires market_maker.approve.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 200, description: 'Queue')],
    )]
    public function queue(ListMarketMakerQueueAction $queue): JsonResponse
    {
        $result = $queue->handle();

        return response()->json(['data' => $result['rows']->values(), 'meta' => ['min_list_age_days' => $result['days']]]);
    }

    #[OA\Get(
        path: '/dashboard/market-maker/approvals',
        operationId: 'dashboardMarketMakerApprovals',
        summary: 'Approved pieces',
        description: 'Spec 019 FR-062. Newest first, with the numbers the approver saw. Requires market_maker.approve.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 200, description: 'Approvals')],
    )]
    public function approvals(): JsonResponse
    {
        $rows = MarketMakerApproval::query()->with(['approver:staff_id,full_name', 'listing.pieceType'])
            ->orderByDesc('approved_at')->limit(200)->get()
            ->map(fn (MarketMakerApproval $a) => [
                'approval_id' => $a->approval_id,
                'listing_id' => $a->listing_id,
                'title' => $a->listing?->title(),
                'asking_price' => $a->asking_price,
                'gold_value' => $a->gold_value,
                'rapaport_guide' => $a->rapaport_guide,
                'approved_by' => $a->approver === null ? null : ['id' => $a->approver->staff_id, 'full_name' => $a->approver->full_name],
                'approved_at' => $a->approved_at->toIso8601String(),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    #[OA\Post(
        path: '/dashboard/market-maker/approvals',
        operationId: 'dashboardApproveForMarketMakers',
        summary: 'Approve a piece for market makers',
        description: 'Spec 019 FR-062. The piece must be live (listing_not_live) and old enough (listing_too_new), and approved once (already_approved). The numbers seen are kept. Audited (market_maker.approved). Requires market_maker.approve and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 201, description: 'Approved'), new OA\Response(response: 409, description: 'already_approved | listing_not_live', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')), new OA\Response(response: 422, description: 'listing_too_new | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function approve(Request $request, ApproveForMarketMakersAction $approve): JsonResponse
    {
        $v = $request->validate([
            'listing_id' => ['required', 'uuid'],
            'asking_price_seen' => ['required', 'numeric', 'min:0'],
            'gold_value_seen' => ['nullable', 'numeric', 'min:0'],
            'rapaport_guide_seen' => ['nullable', 'numeric', 'min:0'],
        ]);

        $a = $approve->handle(
            $request->user('staff'),
            $v['listing_id'],
            (string) $v['asking_price_seen'],
            isset($v['gold_value_seen']) ? (string) $v['gold_value_seen'] : null,
            isset($v['rapaport_guide_seen']) ? (string) $v['rapaport_guide_seen'] : null,
            $request->attributes->get('context'),
        );

        return response()->json(['data' => ['approval_id' => $a->approval_id, 'listing_id' => $a->listing_id, 'approved_at' => $a->approved_at->toIso8601String()]], 201);
    }

    #[OA\Get(
        path: '/dashboard/market-maker/cost',
        operationId: 'dashboardMarketMakerCost',
        summary: 'What the programme gave up in a month',
        description: 'Spec 019 FR-062. Pieces bought with a code, their value, and the spread paid to dealers. Zero until purchases exist. `month=YYYY-MM`, default this month. Requires promo.manage or market_maker.approve.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 200, description: 'The month')],
    )]
    public function cost(Request $request): JsonResponse
    {
        $v = $request->validate(['month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $start = CarbonImmutable::createFromFormat('!Y-m', $v['month'] ?? now()->format('Y-m'));
        $end = $start->addMonth();

        // A use belongs to its buy request; the order is the one that request became.
        $requestIds = PromoCodeUse::query()->where('allowed', true)->whereNotNull('buy_request_id')
            ->where('used_at', '>=', $start)->where('used_at', '<', $end)->pluck('buy_request_id');
        $orderIds = $requestIds->isEmpty() ? collect() : Order::query()->whereIn('buy_request_id', $requestIds)->pluck('order_id');

        $orders = $orderIds->isEmpty() ? collect() : Order::query()->whereIn('order_id', $orderIds)->get(['order_id', 'final_buyer_total', 'locked_total_price', 'spread_amount']);

        $value = $orders->sum(fn (Order $o) => (float) ($o->final_buyer_total ?? $o->locked_total_price));
        $spread = $orders->sum(fn (Order $o) => max(0.0, (float) $o->spread_amount));

        return response()->json(['data' => [
            'month' => $start->format('Y-m'),
            'pieces_bought' => $orders->count(),
            'value_egp' => number_format($value, 4, '.', ''),
            'spread_paid_to_dealers_egp' => number_format($spread, 4, '.', ''),
            // Commission is waived outright on these sales: nothing is charged, so nothing is estimated.
            'commission_waived' => true,
        ]]);
    }
}
