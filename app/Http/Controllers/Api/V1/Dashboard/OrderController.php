<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Listings\ListListingsForReviewAction;
use App\Actions\Orders\CancelAcceptanceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Order\CancelOrderRequest;
use App\Http\Resources\Customer\OrderSummaryResource;
use App\Http\Resources\Staff\ListingResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Orders, staff side (spec 011). Only the cancellation of an acceptance
 * exists until the orders module: the one exit for a deposit held on an
 * accepted order (FR-020a, research R22).
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
}
