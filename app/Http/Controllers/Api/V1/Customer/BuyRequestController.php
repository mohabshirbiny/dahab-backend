<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\BuyRequests\LeaveQueueAction;
use App\Actions\BuyRequests\ListOwnBuyRequestsAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BuyRequest\LeaveQueueRequest;
use App\Http\Requests\Customer\BuyRequest\ListBuyRequestsRequest;
use App\Http\Requests\Customer\BuyRequest\SendBuyRequestRequest;
use App\Http\Resources\Customer\BuyRequestResource;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Support\Wallet\HeldByRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Buying, buyer side (spec 011 US1/US2; Part 2 §4). Sending needs the trade
 * gate (verified and not suspended); reading and leaving need a verified
 * customer (a suspended buyer may read and leave). Every POST needs an
 * Idempotency-Key. A buyer reaches only their own requests (forced RLS).
 */
class BuyRequestController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/customer/me/buy-requests',
        operationId: 'customerSendBuyRequest',
        summary: 'Send a buy request (join the queue)',
        description: 'Spec 011 FR-001–FR-008, Part 2 §4. On a live or reserved piece that is not yours: locks the fresh price (refused as price_moved when it is further from confirm_locked_price than buyrequest.price_tolerance_pct), holds deposit.buyer_pct of it from your wallet (a balanced deposit_hold ledger entry: available → held), takes the next place in line, records your acceptance of the deposit terms, and moves the piece live → reserved on the first request — one transaction. The seller is told after commit. Trade gate. Idempotent: a replay holds nothing twice.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendBuyRequestRequest')),
        tags: ['Customer Buy Requests'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'The request, queued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BuyRequest')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (never on the market)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'listing_not_purchasable | cannot_buy_own_listing | already_in_queue | price_moved (details: current_price, deposit_amount) | price_unavailable | insufficient_funds (details: deposit_amount, available, shortfall) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'deposit_agreement_required | validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(SendBuyRequestRequest $request, SendBuyRequestAction $send, ListOwnBuyRequestsAction $list): JsonResponse
    {
        $buyRequest = $send->handle(
            $this->customer($request),
            (string) $request->validated('listing_id'),
            (string) $request->validated('confirm_locked_price'),
            (int) $request->validated('deposit_legal_doc_id'),
            $request->attributes->get('context'),
        );

        return $this->respond($request, $list->show($this->customer($request)->customer_id, $buyRequest->buy_request_id), 201);
    }

    #[OA\Get(
        path: '/customer/me/buy-requests',
        operationId: 'customerBuyRequests',
        summary: 'My buy requests',
        description: 'Spec 011 FR-009. Newest first, keyset-paginated; optionally one state and/or one listing (the piece page uses listing_id + state=queued to show your place). Read under your own row isolation. Verified customers; a suspended customer may read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Buy Requests'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued', 'accepted', 'released_not_chosen', 'released_declined', 'released_expired', 'withdrawn_by_buyer'])),
            new OA\Parameter(name: 'listing_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of requests', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BuyRequest')),
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
    public function index(ListBuyRequestsRequest $request, ListOwnBuyRequestsAction $list): JsonResponse
    {
        $perPage = $request->perPage(20);
        $page = $list->handle($this->customer($request)->customer_id, $request->state(), $request->listingId(), $request->cursor(), $perPage);
        app(HeldByRequest::class)->prime($page['rows']->pluck('buy_request_id')->all());

        return response()->json([
            'data' => BuyRequestResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/buy-requests/{buyRequest}',
        operationId: 'customerBuyRequest',
        summary: 'One of my buy requests',
        description: 'Spec 011 FR-009. 404 for anyone else\'s request (row isolation).',
        security: [['customerBearer' => []]],
        tags: ['Customer Buy Requests'],
        parameters: [new OA\Parameter(name: 'buyRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BuyRequest')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $buyRequest, ListOwnBuyRequestsAction $list): JsonResponse
    {
        return $this->respond($request, $list->show($this->customer($request)->customer_id, $buyRequest));
    }

    #[OA\Post(
        path: '/customer/me/buy-requests/{buyRequest}/withdraw',
        operationId: 'customerLeaveQueue',
        summary: 'Leave the queue',
        description: 'Spec 011 FR-010, FR-011, Part 2 §4. queued → withdrawn_by_buyer; the deposit goes back to your available balance (deposit_release) at once; the piece is live again if nobody is left in line. With notify_when_free you are told once if it later returns to the market with nobody in line. Verified customers (a suspended buyer may leave). Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/LeaveQueueRequest')),
        tags: ['Customer Buy Requests'],
        parameters: [
            new OA\Parameter(name: 'buyRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The request, withdrawn', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BuyRequest')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'not_in_queue | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function withdraw(LeaveQueueRequest $request, string $buyRequest, LeaveQueueAction $leave, ListOwnBuyRequestsAction $list): JsonResponse
    {
        $left = $leave->handle($this->customer($request), $buyRequest, $request->notifyWhenFree());

        return $this->respond($request, $list->show($this->customer($request)->customer_id, $left->buy_request_id));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }

    private function respond(Request $request, BuyRequest $buyRequest, int $status = 200): JsonResponse
    {
        return response()->json(['data' => BuyRequestResource::make($buyRequest)->resolve($request)], $status);
    }
}
