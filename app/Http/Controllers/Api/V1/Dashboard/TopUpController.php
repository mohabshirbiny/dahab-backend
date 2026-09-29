<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\TopUp\CreditTopUpByHandAction;
use App\Actions\TopUp\ExportTopUpsAction;
use App\Actions\TopUp\HoldTopUpAction;
use App\Actions\TopUp\ListTopUpsAction;
use App\Actions\TopUp\MatchTopUpAction;
use App\Actions\TopUp\ReadTopUpReceiptAction;
use App\Actions\TopUp\RejectTopUpAction;
use App\Actions\TopUp\UnholdTopUpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\TopUp\CreditTopUpByHandRequest;
use App\Http\Requests\Dashboard\TopUp\HoldTopUpRequest;
use App\Http\Requests\Dashboard\TopUp\ListTopUpsRequest;
use App\Http\Requests\Dashboard\TopUp\MatchTopUpRequest;
use App\Http\Requests\Dashboard\TopUp\RejectTopUpRequest;
use App\Http\Resources\Staff\TopUpResource;
use App\Models\TopUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Incoming transfers (spec 009 US2/US3; Part 2 §9 "Match an incoming
 * transfer"). Every route needs `topup.match` (CEO and Finance by default,
 * never the COO). Every POST is idempotent and audited; match and credit by
 * hand move money through the money service.
 */
class TopUpController extends Controller
{
    #[OA\Get(
        path: '/dashboard/topups',
        operationId: 'dashboardTopUps',
        summary: 'Incoming transfers',
        description: 'Spec 009 FR-015. Notices and hand credits, newest first, keyset-paginated. status is a comma list (default pending,on_hold); from/to are Cairo dates (default the last 30 days); q matches the reference (with or without DAHAB-), the phone or the name. meta.totals counts everything the filter matches. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'pending,on_hold')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 64)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of transfers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffTopUp')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'totals', properties: [
                        new OA\Property(property: 'count', type: 'integer'),
                        new OA\Property(property: 'claimed', type: 'string', example: '96400.0000'),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListTopUpsRequest $request, ListTopUpsAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->listQuery(), $request->cursor(), $perPage);

        return response()->json([
            'data' => TopUpResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'totals' => $page['totals']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/topups/export',
        operationId: 'dashboardTopUpsExport',
        summary: 'Incoming transfers as CSV',
        description: 'Spec 009 FR-015. The same filters as the list; newest first; capped at 50,000 rows (X-Export-Truncated: true when the cap cut it). UTF-8 with a BOM. Every export is audited (topup.list_exported). Requires topup.match.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function export(ListTopUpsRequest $request, ExportTopUpsAction $export): Response
    {
        $query = $request->listQuery();
        $result = $export->handle($request->user('staff'), $query);

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="incoming-transfers-'.$query->from.'-'.$query->to.'.csv"',
            'X-Export-Truncated' => $result['truncated'] ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Truncated',
        ]);
    }

    #[OA\Get(
        path: '/dashboard/topups/{topup}',
        operationId: 'dashboardTopUp',
        summary: 'One incoming transfer',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The transfer', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $topup): JsonResponse
    {
        return TopUpResource::make(TopUp::query()->with(ListTopUpsAction::RELATIONS)->findOrFail($topup))->response();
    }

    #[OA\Get(
        path: '/dashboard/topups/{topup}/receipt',
        operationId: 'dashboardTopUpReceipt',
        summary: 'The receipt the customer attached',
        description: 'Spec 009 FR-011. The decrypted image or PDF with its content type; never cached. 404 when the notice has no receipt. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The receipt bytes (image/* or application/pdf)'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found (no such transfer, or no receipt)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function receipt(string $topup, ReadTopUpReceiptAction $read): Response
    {
        $receipt = $read->handle($topup);

        return response($receipt['bytes'], 200, [
            'Content-Type' => $receipt['mime'],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[OA\Post(
        path: '/dashboard/topups/{topup}/match',
        operationId: 'dashboardMatchTopUp',
        summary: 'Match a transfer notice and credit the wallet',
        description: 'Spec 009 FR-016/FR-017/FR-020, Part 2 §9. Credits what actually arrived in one transaction: a topup ledger entry (bank −amount, customer available +amount) attributed to you, the notice marked credited with the ledger id (at most once), and an audit row (topup.matched). A note is required when the amount differs from the claim; the arrival_reference is required when the customer is suspended. The customer gets an SMS/email after commit. Idempotent. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardMatchTopUp')),
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Credited', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'illegal_topup_transition (already credited, rejected or cancelled) | idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (amount, account method, note on a difference, arrival_reference for a suspended customer) | idempotency_key_mismatch', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function match(MatchTopUpRequest $request, string $topup, MatchTopUpAction $match): JsonResponse
    {
        $matched = $match->handle(
            $request->user('staff'),
            $topup,
            (string) $request->validated('amount'),
            (int) $request->validated('receiving_account_id'),
            $request->validated('note'),
            $request->validated('arrival_reference'),
            $request->attributes->get('context'),
        );

        return $this->staffView($matched);
    }

    #[OA\Post(
        path: '/dashboard/topups/{topup}/hold',
        operationId: 'dashboardHoldTopUp',
        summary: 'Put a notice on hold while you check it',
        description: 'Spec 009 FR-025a. pending → on_hold (it stays matchable). Audited (topup.held); no customer message. Idempotent. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardHoldTopUp')),
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'On hold', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'illegal_topup_transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function hold(HoldTopUpRequest $request, string $topup, HoldTopUpAction $hold): JsonResponse
    {
        return $this->staffView($hold->handle($request->user('staff'), $topup, (string) $request->validated('note'), $request->attributes->get('context')));
    }

    #[OA\Post(
        path: '/dashboard/topups/{topup}/unhold',
        operationId: 'dashboardUnholdTopUp',
        summary: 'Take a notice off hold',
        description: 'Spec 009 FR-025a. on_hold → pending. Audited (topup.unheld). Idempotent. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pending again', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'illegal_topup_transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function unhold(Request $request, string $topup, UnholdTopUpAction $unhold): JsonResponse
    {
        return $this->staffView($unhold->handle($request->user('staff'), $topup, $request->attributes->get('context')));
    }

    #[OA\Post(
        path: '/dashboard/topups/{topup}/reject',
        operationId: 'dashboardRejectTopUp',
        summary: 'Reject a transfer notice',
        description: 'Spec 009 FR-025a. pending or on_hold → rejected (final); no money moves. The customer is told the reason (SMS/email after commit), never the note. Audited (topup.rejected). Idempotent. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardRejectTopUp')),
        tags: ['Dashboard Top-ups'],
        parameters: [
            new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'illegal_topup_transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function reject(RejectTopUpRequest $request, string $topup, RejectTopUpAction $reject): JsonResponse
    {
        return $this->staffView($reject->handle($request->user('staff'), $topup, $request->reason(), (string) $request->validated('note'), $request->attributes->get('context')));
    }

    #[OA\Post(
        path: '/dashboard/topups',
        operationId: 'dashboardCreditTopUpByHand',
        summary: 'Credit money that arrived without a notice',
        description: 'Spec 009 US3, FR-018. Records a credited transfer (origin by_hand) and posts the topup ledger entry in one transaction, audited (topup.credited_by_hand). Verified and active customers; verified and suspended customers only with arrival_reference (staff-side reconciliation of money that already arrived; the customer side stays closed); customers awaiting verification or rejected are refused (verification_required). The customer gets an SMS/email after commit. Idempotent. Requires topup.match.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardCreditTopUpByHand')),
        tags: ['Dashboard Top-ups'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Credited', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffTopUp')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (incl. an unknown customer_id, arrival_reference for a suspended customer) | idempotency_key_mismatch', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(CreditTopUpByHandRequest $request, CreditTopUpByHandAction $credit): JsonResponse
    {
        $topUp = $credit->handle(
            $request->user('staff'),
            (string) $request->validated('customer_id'),
            (string) $request->validated('amount'),
            (int) $request->validated('receiving_account_id'),
            (string) $request->validated('note'),
            $request->validated('arrival_reference'),
            $request->attributes->get('context'),
        );

        return $this->staffView($topUp, 201);
    }

    private function staffView(TopUp $topUp, int $status = 200): JsonResponse
    {
        return TopUpResource::make($topUp->load(ListTopUpsAction::RELATIONS))->response()->setStatusCode($status);
    }
}
