<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\MarketMakers\ApproveForMarketMakersAction;
use App\Actions\MarketMakers\CreatePromoCodeAction;
use App\Actions\MarketMakers\LeaveMarketMakerQueueAction;
use App\Actions\MarketMakers\ListMarketMakerQueueAction;
use App\Actions\MarketMakers\MarketMakerCostAction;
use App\Actions\MarketMakers\ReadQueueMediaAction;
use App\Actions\MarketMakers\UpdatePromoCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Promo\CreatePromoCodeRequest;
use App\Models\MarketMakerApproval;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Support\Promo\PromoCodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        summary: 'Promo codes',
        description: 'Spec 019 FR-060, spec 023 Story 5. Each code with what it does (commission mode and percent, the spread, the advance), who it is tied to (the account with a masked phone, or anyone), its conditions (use limit, age and approval for dealer codes, dates), its cap, whether it is on, and its allowed non-voided uses and value this Cairo month. Requires promo.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 200, description: 'Codes'), new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function codes(PromoCodePresenter $presenter): JsonResponse
    {
        $codes = PromoCode::query()->with('customer:customer_id,display_ref,full_name,phone')->orderByDesc('created_at')->get();

        return response()->json(['data' => $presenter->rows($codes)]);
    }

    #[OA\Post(
        path: '/dashboard/promo-codes',
        operationId: 'dashboardCreatePromoCode',
        summary: 'Create a promo code',
        description: 'Spec 019 FR-060, spec 023 Story 5. The three kinds from one body: a dealer code (tied to a market maker, waives the commission, gives the spread), a first-sale code (pays the first gold sale in advance) and a seller-side discount (waive or reduce the commission by a percentage), for one account (`tied_to` an account number or phone, or `tied_customer_id`) or anyone. `kind` is inferred when absent; a contradiction is validation_failed. The old body {code, tied_customer_id, monthly_cap_egp} still creates a dealer code. By product and side (docs/features/promo-code-product-side.md): `side` (seller|buyer) and `product_type` (gold|diamond|gold_with_diamond). A seller code reduces the commission by `commission_mode` waive | percent | amount (`commission_amount_egp`); `side` sent makes `product_type` required, and the older body without it covers every product. A buyer code (`kind` buyer; for anyone, or one account) on gold gives the buyer the price the seller gets (the positive spread is handed back; no deposit fields); on diamond and gold_with_diamond it lowers the deposit by `deposit_percent` or `deposit_amount_egp` (one of the two; never below 1 EGP). Terms are immutable afterwards. Audited (promo.created). Requires promo.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        responses: [new OA\Response(response: 201, description: 'Created (the list item)'), new OA\Response(response: 409, description: 'promo_code_exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')), new OA\Response(response: 422, description: 'promo_account_not_found | promo_customer_not_market_maker | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function create(CreatePromoCodeRequest $request, CreatePromoCodeAction $create, PromoCodePresenter $presenter): JsonResponse
    {
        $promo = $create->handle($request->user('staff'), $request->terms(), $request->attributes->get('context'));

        return response()->json(['data' => $presenter->one($promo)], 201);
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
            ->orderByDesc('used_at')->limit(500)->get();

        $credits = $rows->pluck('credit_id')->filter()->unique()->values();
        $status = $credits->isEmpty() ? collect() : DB::table('commission_credit')->whereIn('credit_id', $credits)->pluck('status', 'credit_id');
        $reduction = $credits->isEmpty() ? collect() : DB::table('commission_credit_event')
            ->where('kind', 'spent')->whereIn('credit_id', $credits)->groupBy('credit_id')
            ->selectRaw('credit_id, sum(reduction_egp) as r')->pluck('r', 'credit_id');

        $out = $rows->map(fn (PromoCodeUse $u) => [
            'used_at' => $u->used_at->toIso8601String(),
            'customer' => ['display_ref' => $u->customer?->display_ref, 'full_name' => $u->customer?->full_name],
            'allowed' => $u->allowed,
            'block_reason' => $u->block_reason,
            'order_id' => $u->order_id,
            'value_egp' => $u->amount_egp === null ? null : number_format((float) $u->amount_egp, 4, '.', ''),
            'listing_ref' => $u->listing_id,
            'buy_request_ref' => $u->buy_request_id,
            'credit_status' => $u->credit_id === null ? null : ($status[$u->credit_id] ?? null),
            'reduction_egp' => $u->credit_id === null || ! isset($reduction[$u->credit_id]) ? null : number_format((float) $reduction[$u->credit_id], 4, '.', ''),
        ])->values();

        return response()->json(['data' => $out]);
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

        return response()->json(['data' => $result['rows']->values(), 'meta' => [
            'min_list_age_days' => $result['days'],
            'require_approval' => $result['require_approval'],
        ]]);
    }

    #[OA\Post(
        path: '/dashboard/market-maker/queue/{listing}/leave',
        operationId: 'dashboardLeaveMarketMakerQueue',
        summary: 'Leave a piece as it is',
        description: 'Spec 023 R20 (D23-7). Changes nothing about the piece, which stays in the queue; logged with the figures seen (market_maker.left). Optional note up to 500 characters. 404 when the piece is not waiting, 409 listing_not_live. market_maker.approve. Idempotent.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Left'), new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function leave(Request $request, string $listing, LeaveMarketMakerQueueAction $leave): JsonResponse
    {
        $v = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $leave->handle($request->user('staff'), $listing, $v['note'] ?? null, $request->attributes->get('context'))]);
    }

    #[OA\Get(
        path: '/dashboard/market-maker/queue/{listing}/media/{media}',
        operationId: 'dashboardMarketMakerQueueMedia',
        summary: 'A file of a piece in the approvals queue',
        description: 'Spec 023 contract staff-promo-api §7. The decrypted file, streamed, the private invoice included; every open is audited (market_maker.media_viewed). Only a live piece. market_maker.approve.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'media', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))],
    )]
    public function queueMedia(Request $request, string $listing, string $media, ReadQueueMediaAction $read): StreamedResponse
    {
        return $read->handle($request->user('staff'), $listing, $media, $request->attributes->get('context'));
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
    public function cost(Request $request, MarketMakerCostAction $cost): JsonResponse
    {
        $v = $request->validate(['month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return response()->json(['data' => $cost->handle($v['month'] ?? null)]);
    }
}
