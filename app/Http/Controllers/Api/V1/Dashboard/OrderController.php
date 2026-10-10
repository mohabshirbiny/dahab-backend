<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Inspections\RecordInspectionResultAction;
use App\Actions\Listings\ListListingsForReviewAction;
use App\Actions\Orders\CancelAcceptanceAction;
use App\Actions\Orders\Staff\ChangeOrderBranchAction;
use App\Actions\Orders\Staff\ExportOrdersAction;
use App\Actions\Orders\Staff\ExtendOrderDeadlineAction;
use App\Actions\Orders\Staff\HandoverPieceAction;
use App\Actions\Orders\Staff\HandoverReturnedPieceAction;
use App\Actions\Orders\Staff\ListOrdersAction;
use App\Actions\Orders\Staff\ProposePriceAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Actions\Orders\Staff\ShowOrderAction;
use App\Actions\Orders\Staff\ViewProxyIdAction;
use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Order\CancelOrderRequest;
use App\Http\Requests\Dashboard\Order\ChangeBranchRequest;
use App\Http\Requests\Dashboard\Order\ExtendDeadlineRequest;
use App\Http\Requests\Dashboard\Order\HandoverRequest;
use App\Http\Requests\Dashboard\Order\ListOrdersRequest;
use App\Http\Requests\Dashboard\Order\ProposePriceRequest;
use App\Http\Requests\Dashboard\Order\RecordInspectionResultRequest;
use App\Http\Resources\Customer\OrderSummaryResource;
use App\Http\Resources\Staff\InspectionResultResource;
use App\Http\Resources\Staff\ListingResource;
use App\Http\Resources\Staff\StaffOrderResource;
use App\Http\Resources\Staff\WorkListItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Orders, staff side (spec 011 cancel; spec 012 the life after acceptance:
 * the detail, receiving the piece, the inspection result and the regrade
 * price, handing a returned piece back). Each action needs its own catalogue
 * permission; the branch actions are scoped to the staff member's assigned
 * branch. Every POST needs an Idempotency-Key and is audited.
 */
class OrderController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/orders/{order}/cancel',
        operationId: 'dashboardCancelOrder',
        summary: 'Cancel an acceptance',
        description: 'Spec 011 FR-020a, research R22; Part 1 §4.1 "Cancel an order". Only an order awaiting delivery. One transaction: the order moves to cancelled_staff with who, when and the reason; the buyer\'s deposit is released in full (deposit_release, tied to the request and the order); the piece moves accepted → live (relist: back on the market, nobody in line) or accepted → withdrawn (final), with the reason in its history. Audited (order.cancelled). Buyer and seller are told after commit. Not a seller cancellation. Idempotent. Requires order.cancel.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CancelOrderRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cancelled order, the listing and the refund', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'order', ref: '#/components/schemas/OrderSummary'),
                    new OA\Property(property: 'listing', ref: '#/components/schemas/DashboardListing'),
                    new OA\Property(property: 'refunded', type: 'string', example: '11640.0000'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'order_not_cancellable | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed (reason 10–1000 characters, relist boolean) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function cancel(CancelOrderRequest $request, string $order, CancelAcceptanceAction $cancel, ListListingsForReviewAction $list): JsonResponse
    {
        $done = $cancel->handle(
            $request->user('staff'),
            $order,
            (string) $request->validated('reason'),
            (bool) $request->validated('relist'),
            $request->attributes->get('context'),
        );

        $found = $list->show($done['listing']->listing_id);
        $done['order']->loadMissing('branch');

        return response()->json(['data' => [
            'order' => OrderSummaryResource::shape($done['order']),
            'listing' => ListingResource::make($found['listing'])->resolve($request),
            'refunded' => bcadd($done['refunded'], '0', 4),
        ]]);
    }

    #[OA\Get(
        path: '/dashboard/orders',
        operationId: 'dashboardOrders',
        summary: 'Orders',
        description: 'Spec 012 FR-022, research R18. Newest first, keyset-paginated. group = open (default: every non-final state) | waiting_seller | at_igi | needs_decision | waiting_balance | ready_to_collect | returns (a returned piece still waiting) | closed | all; past_deadline = the deadline running in the order\'s state has passed; branch_id; q = an order reference or a customer display reference. meta.counts gives a count per group and past_deadline (with the same branch and search). Requires order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'waiting_seller', 'at_igi', 'needs_decision', 'waiting_balance', 'ready_to_collect', 'returns', 'closed', 'all'])),
            new OA\Parameter(name: 'past_deadline', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'free_relist', in: 'query', required: false, description: 'Spec 018: the state of the buyer free-relist offer; without group it looks at every order', schema: new OA\Schema(type: 'string', enum: ['open', 'used', 'expired'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 40)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of orders', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffOrder')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'counts', type: 'object', description: 'open, waiting_seller, at_igi, needs_decision, waiting_balance, ready_to_collect, returns, past_deadline'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListOrdersRequest $request, ListOrdersAction $list): JsonResponse
    {
        $perPage = $request->perPage(25);
        $page = $list->handle($request->group(), $request->pastDeadline(), $request->branchId(), $request->search(), $request->cursor(), $perPage, $request->freeRelist());

        return response()->json([
            'data' => StaffOrderResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'counts' => $page['counts']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/orders/export',
        operationId: 'dashboardOrdersExport',
        summary: 'The filtered Orders list as CSV',
        description: 'Spec 015 FR-018. The list\'s filters (group, past_deadline, branch_id, q), newest first; UTF-8 with BOM; capped at 10,000 rows (X-Export-Truncated); audited as order.list_exported with the filters and the row count. Requires order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'past_deadline', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'free_relist', in: 'query', required: false, description: 'Spec 018: the state of the buyer free-relist offer; without group it looks at every order', schema: new OA\Schema(type: 'string', enum: ['open', 'used', 'expired'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function export(ListOrdersRequest $request, ExportOrdersAction $export): Response
    {
        return CompensationController::csv($export->handle($request->user('staff'), $request->group(), $request->pastDeadline(),
            $request->branchId(), $request->search(), $request->freeRelist()), 'orders');
    }

    #[OA\Get(
        path: '/dashboard/orders/{order}',
        operationId: 'dashboardOrder',
        summary: 'One order',
        description: 'Spec 012 FR-022, research R18. The order with its figures, every inspection result (corrections flagged), the buyer\'s decision, the collection and return, branch changes, extensions, the ledger entries tied to it, its history and what you may do now (`can`). Buyer and seller by display reference and id. Never a collection code. Requires order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffOrder')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $order, ShowOrderAction $show): JsonResponse
    {
        return response()->json(['data' => StaffOrderResource::make($show->handle($order))->detail()->resolve($request)]);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/receive',
        operationId: 'dashboardReceivePiece',
        summary: 'Mark the piece received at the branch',
        description: 'Spec 012 FR-003, FR-004; Part 3 §5.3. awaiting_delivery → at_inspection, the listing accepted → at_inspection. Staff with an assigned branch act only at that branch (wrong_branch, audited); staff with none act anywhere. Audited (order.received); both parties told. The answer is the order for holders of order.view, else the work-list item (no prices). Idempotent. Requires order.receive.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'StaffOrder (with order.view) or WorkListItem', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden | wrong_branch (both audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function receive(Request $request, string $order, ReceivePieceAction $receive, ShowOrderAction $show): JsonResponse
    {
        $receive->handle($request->user('staff'), $order, $request->attributes->get('context'));

        return $this->respond($request, $order, $show);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/inspection-results',
        operationId: 'dashboardRecordInspectionResult',
        summary: 'Enter an inspection result',
        description: 'Spec 012 FR-011–FR-012a; Part 2 §6, Part 3 §7. Immutable. The server derives karat_mismatch, weight_diff_pct and the outcome: any karat difference → karat_cancel; counterfeit → fake_cancel; a stone below its claim → stone_regrade; |weight difference| ≤ inspection.weight_tolerance_pct → pass; else weight_adjust. Effects in the same transaction: pass → awaiting_balance (balance deadline); weight_adjust → the buyer decides on the price at the measured weight; stone_regrade → staff propose a price, then the buyer decides; karat/fake → cancelled_inspection, the buyer refunded, the seller suspended (piece_misrepresented), the piece returned with no compensation. A correction supersedes the latest result while waiting for the buyer or the balance, before any decision or payment. Branch-scoped. Audited (inspection.result_recorded). The answer never carries money. Idempotent. Requires inspection.enter.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RecordInspectionResultRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The result and the order state', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'inspection', ref: '#/components/schemas/InspectionResult'),
                new OA\Property(property: 'order_state', type: 'string'),
            ], type: 'object')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden | wrong_branch (both audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | inspection_correction_not_allowed | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function inspectionResult(RecordInspectionResultRequest $request, string $order, RecordInspectionResultAction $record): JsonResponse
    {
        $done = $record->handle($request->user('staff'), $order, $request->validated(), $request->attributes->get('context'));

        return response()->json(['data' => [
            'inspection' => InspectionResultResource::shape($done['inspection']),
            'order_state' => $done['order']->state->value,
        ]], 201);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/propose-price',
        operationId: 'dashboardProposePrice',
        summary: 'Propose the new price after a stone regrade',
        description: 'Spec 012 research R11. Once, while the order waits on a stone regrade with no price. The buyer\'s decision deadline starts now (deadline.buyer_pay_days calendar days); both parties told. Audited (order.price_proposed, with the reason). Idempotent. Requires order.price_adjust.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProposePriceRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffOrder')])),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function proposePrice(ProposePriceRequest $request, string $order, ProposePriceAction $propose, ShowOrderAction $show): JsonResponse
    {
        $propose->handle($request->user('staff'), $order, (string) $request->validated('price'), (string) $request->validated('reason'), $request->attributes->get('context'));

        return $this->respond($request, $order, $show);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/seller-return/handover',
        operationId: 'dashboardHandoverReturnedPiece',
        summary: 'Hand a returned piece back to its seller',
        description: 'Spec 012 FR-019, research R10, R13. Against the seller\'s 6-digit code: the listing becomes withdrawn (from awaiting_seller_return or seller_unclaimed), the return is collected, no money moves. A wrong code answers 422 invalid_collection_code with the attempts left (audited); the fifth locks the handover for 15 minutes (429 handover_locked, retry_after). Branch-scoped. Audited (order.return_handed_over). Idempotent. Requires order.handover.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HandoverRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'StaffOrder (with order.view) or WorkListItem', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'forbidden | wrong_branch (both audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (no piece waiting) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'invalid_collection_code (details: attempts_left) | validation_failed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'handover_locked (details: retry_after seconds)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function sellerReturnHandover(HandoverRequest $request, string $order, HandoverReturnedPieceAction $handover, ShowOrderAction $show): JsonResponse
    {
        $handover->handle($request->user('staff'), $order, (string) $request->validated('code'), $request->attributes->get('context'));

        return $this->respond($request, $order, $show);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/handover',
        operationId: 'dashboardHandoverPiece',
        summary: 'Hand a paid piece to its buyer',
        description: 'Spec 012 FR-020, FR-021, research R10. Against the buyer\'s 6-digit code (check their ID at the counter): ready_to_collect → completed, no money (settlement happened at payment); a piece past its collection window (uncollected_expired) goes back to sold first. A wrong code answers 422 invalid_collection_code with attempts_left (audited); the fifth locks the handover for 15 minutes (429 handover_locked, retry_after). Spec 014: when the buyer named someone else and that person collects, send collector = proxy and proxy_id_checked = true (their ID checked against the named proxy, GET /dashboard/orders/{order}/proxy-id); otherwise 422 proxy_details_missing, no attempt counted. A frozen (disputed) order answers 409 order_frozen. Branch-scoped. Audited (order.handed_over). Idempotent. Requires order.handover.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HandoverRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'StaffOrder (with order.view) or WorkListItem', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'forbidden | wrong_branch (both audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'invalid_collection_code (details: attempts_left) | proxy_details_missing | validation_failed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'handover_locked (details: retry_after seconds)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function handover(HandoverRequest $request, string $order, HandoverPieceAction $handover, ShowOrderAction $show): JsonResponse
    {
        $handover->handle($request->user('staff'), $order, (string) $request->validated('code'), $request->attributes->get('context'),
            $request->byProxy(), $request->boolean('proxy_id_checked'));

        return $this->respond($request, $order, $show);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/change-branch',
        operationId: 'dashboardChangeOrderBranch',
        summary: 'Move an open order to another branch',
        description: 'Spec 012 FR-008, Part 3 §5.2. Only while the piece has not reached a branch (order_not_open). The branch must be one the seller named and enabled (branch_not_in_options). The reach-branch clock keeps running unless extend_to sets a later deadline (then also an extension; deadline_must_move_forward otherwise). Audited (order.branch_changed, with the reason); both parties told. Idempotent. Requires order.change_branch.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChangeBranchRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffOrder')])),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'order_not_open | branch_not_in_options | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'deadline_must_move_forward | validation_failed (incl. the same branch) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function changeBranch(ChangeBranchRequest $request, string $order, ChangeOrderBranchAction $change, ShowOrderAction $show): JsonResponse
    {
        $change->handle($request->user('staff'), $order, (int) $request->validated('branch_id'), (string) $request->validated('reason'),
            $request->extendTo(), $request->attributes->get('context'));

        return $this->respond($request, $order, $show);
    }

    #[OA\Post(
        path: '/dashboard/orders/{order}/extend-deadline',
        operationId: 'dashboardExtendOrderDeadline',
        summary: 'Extend a running order deadline',
        description: 'Spec 012 FR-009, Part 3 §1.4. which = reach_branch (awaiting delivery), balance (awaiting the balance) or collect (ready to collect, not yet collected); otherwise deadline_not_running. The new deadline must be later than the current one and in the future (deadline_must_move_forward). Its reminder may fire again; an extended collection window puts an uncollected piece back to sold. Audited (order.deadline_extended, with the reason); both parties told. Idempotent. Requires order.extend_deadline.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ExtendDeadlineRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffOrder')])),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'deadline_not_running | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'deadline_must_move_forward | validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function extendDeadline(ExtendDeadlineRequest $request, string $order, ExtendOrderDeadlineAction $extend, ShowOrderAction $show): JsonResponse
    {
        $extend->handle($request->user('staff'), $order, $request->which(), $request->newDeadline(), (string) $request->validated('reason'),
            $request->attributes->get('context'));

        return $this->respond($request, $order, $show);
    }

    /** The order for holders of order.view; the money-free work-list item otherwise (Part 1 §3.4). */
    #[OA\Get(
        path: '/dashboard/orders/{order}/proxy-id',
        operationId: 'dashboardOrderProxyId',
        summary: 'Open the ID photo of the person named to collect',
        description: 'Spec 014 FR-020. The decrypted front of the proxy\'s ID, never cached, to check at the counter; each view is audited (order.proxy_id_viewed). 404 when no proxy is named. Requires order.handover or order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The image', content: new OA\MediaType(mediaType: 'image/*')),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (no proxy)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function proxyId(Request $request, string $order, ViewProxyIdAction $view): Response
    {
        $image = $view->handle($request->user('staff'), $order, $request->attributes->get('context'));

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline; filename="proxy-id"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function respond(Request $request, string $orderId, ShowOrderAction $show): JsonResponse
    {
        $order = $show->handle($orderId);
        $staff = $request->user('staff');

        $data = $staff !== null && $staff->can(StaffPermission::ORDER_VIEW->value)
            ? StaffOrderResource::make($order)->detail()->resolve($request)
            : WorkListItemResource::make($order)->resolve($request);

        return response()->json(['data' => $data]);
    }
}
