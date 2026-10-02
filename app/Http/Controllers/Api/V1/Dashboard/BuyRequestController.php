<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\BuyRequests\ListBuyRequestsForStaffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Order\ListBuyRequestsRequest;
use App\Http\Resources\Staff\StaffBuyRequestResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Buy requests across listings, for staff (spec 012 US10; product-owner
 * decision 2026-10-01): read-only, so operations can chase sellers before
 * buyers are released.
 */
class BuyRequestController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/buy-requests',
        operationId: 'dashboardBuyRequests',
        summary: 'Buy requests across listings',
        description: 'Spec 012 FR-023. Soonest seller-reply deadline first, keyset-paginated. state = queued (default) | accepted | ended; listing_id; near_expiry = queued and the reply deadline within 6 hours; branch_id = one of the listing\'s branches. Buyers and sellers by display reference only. meta.counts: queued, near_expiry. Read-only. Requires buy_request.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued', 'accepted', 'ended'])),
            new OA\Parameter(name: 'listing_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'near_expiry', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of buy requests', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffBuyRequest')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'counts', type: 'object', description: 'queued, near_expiry'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'forbidden (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListBuyRequestsRequest $request, ListBuyRequestsForStaffAction $list): JsonResponse
    {
        $perPage = $request->perPage(25);
        $page = $list->handle($request->state(), $request->listingId(), $request->nearExpiry(), $request->branchId(), $request->cursor(), $perPage);

        return response()->json([
            'data' => StaffBuyRequestResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'counts' => $page['counts']],
        ]);
    }
}
