<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\DecideAdjustmentAction;
use App\Actions\Orders\Customer\ListOwnOrdersAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\Customer\RelistReturnedPieceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Order\DecideAdjustmentRequest;
use App\Http\Requests\Customer\Order\ListOrdersRequest;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A customer's orders after acceptance, as buyer or seller (spec 012 US1, US2,
 * US6, US7; Part 2 §5–§7). Reads, the seller's cancel and the buyer's payment
 * need a verified customer (a suspended customer winds down open orders,
 * Part 3 §9.2); relisting a returned piece is new trading (trade gate). Every
 * POST needs an Idempotency-Key. A customer reaches only their own orders
 * (forced RLS); the other party appears by display reference only.
 */
class OrderController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/orders',
        operationId: 'customerOrders',
        summary: 'My orders (as buyer or seller)',
        description: 'Spec 012 FR-001. Newest first, keyset-paginated. Read under your own row isolation. Verified customers; a suspended customer may read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['buyer', 'seller'])),
            new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'closed'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of orders (never a code)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrder')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListOrdersRequest $request, ListOwnOrdersAction $list): JsonResponse
    {
        $perPage = $request->perPage(20);
        $page = $list->handle($this->customer($request)->customer_id, $request->role(), $request->group(), $request->cursor(), $perPage);

        return response()->json([
            'data' => CustomerOrderResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/orders/{order}',
        operationId: 'customerOrder',
        summary: 'One of my orders',
        description: 'Spec 012 FR-001. Adds `collection_code` (the buyer, while ready to collect) and `return_code` (the seller, while a returned piece waits). 404 for anyone else\'s order (row isolation).',
        security: [['customerBearer' => []]],
        tags: ['Customer Orders'],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $order, ListOwnOrdersAction $list): JsonResponse
    {
        return $this->respond($request, $order, $list);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/cancel',
        operationId: 'customerCancelOrder',
        summary: 'Cancel the sale (seller)',
        description: 'Spec 012 FR-005, Part 2 §5. awaiting_delivery → cancelled_seller in one transaction: the buyer\'s deposit is refunded in full (deposit_release), the cancellation counts against you, the piece is withdrawn (you keep it). Reaching suspension.cancellations_threshold since your last reinstatement suspends you within a minute (a separate sweep). Audited (order.seller_cancelled). Verified customers (a suspended seller may cancel). Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order, cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your sale)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function cancel(Request $request, string $order, CancelOrderBySellerAction $cancel, ListOwnOrdersAction $list): JsonResponse
    {
        $cancel->bySeller($this->customer($request), $order, $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/decision',
        operationId: 'customerDecideAdjustment',
        summary: 'Accept or decline an adjusted price (buyer)',
        description: 'Spec 012 FR-013, Part 2 §6. The order waits for you after a weight adjustment, or a stone regrade once Dahab has priced it. Accept → awaiting the balance (deadline.buyer_pay_days calendar days). Decline → cancelled_inspection: your deposit back in full, the seller not suspended, the piece returned to them. Unanswered by decision_due_deadline counts as a decline. Send the inspection_id you are answering (a newer correction makes it stale). Audited (order.decided). Verified customers (a suspended buyer may decide). Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DecideAdjustmentRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your purchase)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | price_not_set | inspection_correction_not_allowed (stale inspection_id) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function decision(DecideAdjustmentRequest $request, string $order, DecideAdjustmentAction $decide, ListOwnOrdersAction $list): JsonResponse
    {
        $decide->byBuyer($this->customer($request), $order, (bool) $request->validated('accept'),
            (string) $request->validated('inspection_id'), $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/pay-balance',
        operationId: 'customerPayBalance',
        summary: 'Pay the balance (buyer) — the settlement',
        description: 'Spec 012 FR-014–FR-016, Part 2 §7, Part 3 §3. From your available balance, in full, before balance_due_deadline. The total is recomputed on the IGI-measured weight with the rates locked at your request (yours) and at acceptance (the seller\'s); balance = total − deposit (an excess deposit comes back). One balanced balance_payment through escrow: the seller is paid, Dahab takes commission, VAT and spread. The order becomes ready_to_collect; the response carries your collection code (also sent by SMS). Audited (order.paid). Verified customers (a suspended buyer may pay). Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order, paid, with collection_code', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your purchase)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'insufficient_funds (details: amount_due, available, shortfall) | balance_deadline_passed | illegal_order_transition | settlement_not_possible | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function payBalance(Request $request, string $order, PayBalanceAction $pay, ListOwnOrdersAction $list): JsonResponse
    {
        $pay->handle($this->customer($request), $order, $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/relist',
        operationId: 'customerRelistReturnedPiece',
        summary: 'Put a returned piece back on the market (seller)',
        description: 'Spec 012 FR-019, Part 3 §10.1. The piece is waiting at the branch for you (awaiting_seller_return): instead of collecting it, put it back live with an empty line. After an inspection a gold piece takes the IGI-measured karat and weight. Audited (order.relisted). Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order, its piece relisted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (no piece waiting) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function relist(Request $request, string $order, RelistReturnedPieceAction $relist, ListOwnOrdersAction $list): JsonResponse
    {
        $relist->handle($this->customer($request), $order, $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }

    private function respond(Request $request, string $orderId, ListOwnOrdersAction $list): JsonResponse
    {
        return response()->json(['data' => CustomerOrderResource::make($list->show($orderId))->withCode()->resolve($request)]);
    }
}
