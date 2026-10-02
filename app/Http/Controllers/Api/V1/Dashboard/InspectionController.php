<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Inspections\InspectionWorkListAction;
use App\Actions\Inspections\ListInspectionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Order\ListInspectionsRequest;
use App\Http\Requests\Dashboard\Order\WorkListRequest;
use App\Http\Resources\Staff\InspectionResultResource;
use App\Http\Resources\Staff\WorkListItemResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The branch side of orders (spec 012 US4, FR-010; Part 1 §3.4): the pieces
 * to receive, inspect, correct and hand over at the caller's branch. Never a
 * price, a wallet figure or a name.
 */
class InspectionController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/inspections/work-list',
        operationId: 'dashboardInspectionWorkList',
        summary: 'Pieces to handle at my branch',
        description: 'Spec 012 FR-010, research R18. Orders awaiting delivery (receive), at inspection (inspect), waiting for the buyer or the balance (correct), ready to collect (hand over) and returned pieces waiting for their seller (return_handover) — at your assigned branch, or every branch (optionally one) when you have none. Oldest first. Only inspection fields: no price, no wallet, no buyer or seller. Requires inspection.enter, order.receive or order.handover.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Ignored for staff with an assigned branch'),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of pieces', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkListItem')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    #[OA\Get(
        path: '/dashboard/inspections',
        operationId: 'dashboardInspections',
        summary: 'Inspection results',
        description: 'Spec 012 FR-022, research R18. Every result, newest first, the corrected ones flagged (superseded); from / to are Cairo dates (default: the last 30 days); branch_id (ignored for staff with an assigned branch, who see theirs only); outcome. Measurements and outcomes only — never money. Requires inspection.enter or order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'outcome', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pass', 'weight_adjust', 'stone_regrade', 'karat_cancel', 'fake_cancel'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of results: InspectionResult plus order_ref, order_state, piece, seller_ref, branch, received_at, superseded', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InspectionResult')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListInspectionsRequest $request, ListInspectionsAction $list): JsonResponse
    {
        $perPage = $request->perPage(25);
        $page = $list->handle($request->user('staff'), $request->validated('from'), $request->validated('to'),
            $request->branchId(), $request->outcome(), $request->cursor(), $perPage);

        return response()->json([
            'data' => $page['rows']->map(fn ($r) => InspectionResultResource::listRow($r, $page['superseded']))->values()->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    public function workList(WorkListRequest $request, InspectionWorkListAction $list): JsonResponse
    {
        $perPage = $request->perPage(50);
        $page = $list->handle($request->user('staff'), $request->branchId(), $request->cursor(), $perPage);

        return response()->json([
            'data' => WorkListItemResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }
}
