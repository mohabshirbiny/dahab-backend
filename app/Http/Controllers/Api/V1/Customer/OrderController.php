<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Actions\Listings\ListOwnListingsAction;
use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\DecideAdjustmentAction;
use App\Actions\Orders\Customer\FreeRelistAction;
use App\Actions\Orders\Customer\ListOwnOrdersAction;
use App\Actions\Orders\Customer\NameProxyAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\Customer\RateOrderAction;
use App\Actions\Orders\Customer\RelistReturnedPieceAction;
use App\Actions\Orders\Customer\RemoveProxyAction;
use App\Actions\Orders\Customer\RequestMoreTimeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Order\DecideAdjustmentRequest;
use App\Http\Requests\Customer\Order\FreeRelistRequest;
use App\Http\Requests\Customer\Order\ListOrdersRequest;
use App\Http\Requests\Customer\Order\NameProxyRequest;
use App\Http\Requests\Customer\Order\OpenDisputeRequest;
use App\Http\Requests\Customer\Order\RateOrderRequest;
use App\Http\Requests\Customer\Order\RequestMoreTimeRequest;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Http\Resources\Customer\DisputeResource;
use App\Http\Resources\Customer\ListingResource;
use App\Models\Customer;
use App\Support\Wallet\HeldByRequest;
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
        $me = $this->customer($request)->customer_id;
        $page = $list->handle($me, $request->role(), $request->group(), $request->cursor(), $perPage);
        app(HeldByRequest::class)->prime($page['rows']->where('buyer_id', $me)->pluck('buy_request_id')->all());

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

    #[OA\Post(
        path: '/customer/me/orders/{order}/free-relist',
        operationId: 'customerFreeRelist',
        summary: 'Relist a collected piece for free (buyer)',
        description: "Spec 018 FR-004–FR-016. Within the free-relist window the staff handover stored (free_relist.ends_at on the order), the buyer puts the piece back on the market with no commission: a NEW live listing is created at once, with no review, owned by the buyer, linked to the order (the link is the waiver — commission, its VAT and the minimum are 0 for every sale of that listing; the buy/sell spread still applies; only the buyer's invoice is issued). The IGI-measured karat and weight, the public photos and the branch options are copied; the original invoice is not. The buyer enters the price (making_charge_per_g for gold, asking_price for stones) and re-accepts the ownership declaration. One free relist per order. Audited (order.free_relisted); the buyer is told by SMS, email and the inbox. Trade gate. Idempotent.",
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FreeRelistRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The new listing (live) and the order (its offer now used)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'listing', ref: '#/components/schemas/CustomerListing'),
                    new OA\Property(property: 'order', ref: '#/components/schemas/CustomerOrder'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended | account_closed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not the buyer)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition (not collected) | free_relist_expired | already_relisted | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | branch_options_required | ownership_declaration_required | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function freeRelist(FreeRelistRequest $request, string $order, FreeRelistAction $relist, ListOwnOrdersAction $list): JsonResponse
    {
        $listing = $relist->handle($this->customer($request), $order, $request->validated(), $request->attributes->get('context'));
        $listing->load(ListOwnListingsAction::RELATIONS);

        return response()->json(['data' => [
            'listing' => ListingResource::make($listing)->resolve($request),
            'order' => CustomerOrderResource::make($list->show($order))->withCode()->resolve($request),
        ]], 201);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/rating',
        operationId: 'customerRateOrder',
        summary: 'Rate an order (buyer or seller)',
        description: 'Spec 018 FR-030–FR-036. Your own rating of the experience with Dahab: 1 to 5 stars and an optional note (500 characters). One per party and order, immutable. The seller from the moment the piece is ready to collect, the buyer once it is completed, for 30 days; never for a cancelled order. A suspended customer may rate. The other party never sees it, nothing changes anywhere else and no one is told. Audited (order.rated, stars only). Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RateOrderRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The rating and the order', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'rating', properties: [
                        new OA\Property(property: 'stars', type: 'integer'),
                        new OA\Property(property: 'note', type: 'string', nullable: true),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ], type: 'object'),
                    new OA\Property(property: 'order', ref: '#/components/schemas/CustomerOrder'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_closed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not a party)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'rating_not_available | rating_closed | already_rated | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function rate(RateOrderRequest $request, string $order, RateOrderAction $rate, ListOwnOrdersAction $list): JsonResponse
    {
        $rating = $rate->handle($this->customer($request), $order, (int) $request->validated('stars'), $request->validated('note'), $request->attributes->get('context'));

        return response()->json(['data' => [
            'rating' => [
                'stars' => $rating->stars,
                'note' => $rating->note,
                'created_at' => $rating->created_at->toIso8601String(),
            ],
            'order' => CustomerOrderResource::make($list->show($order))->withCode()->resolve($request),
        ]], 201);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/disputes',
        operationId: 'customerOpenDispute',
        summary: 'Report a problem — freezes the order (buyer or seller)',
        description: 'Spec 014 FR-001–FR-007. From at_inspection, weight_adjust_pending, awaiting_balance or ready_to_collect: the order becomes disputed at once — no deadline runs, the sweep leaves it alone, and every other action on it answers order_frozen until Dahab resolves it with a reply. One dispute per party per order. 0–5 photos (upload purpose dispute_photo). Both parties are told the order is on hold; the other party never sees what you wrote. Audited (dispute.opened). Verified customers — a suspended customer may report a problem. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/OpenDisputeRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Your dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerDispute')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your order)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | order_frozen | dispute_already_raised | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | upload_token_invalid | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function openDispute(OpenDisputeRequest $request, string $order, OpenDisputeAction $open): JsonResponse
    {
        $dispute = $open->handle($this->customer($request), $order, $request->reason(), trim((string) $request->validated('detail')),
            $request->photoTokens(), $request->attributes->get('context'));
        $dispute->load(['order:order_id,order_ref', 'photos']);

        return response()->json(['data' => (new DisputeResource($dispute))->resolve($request)], 201);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/extension-requests',
        operationId: 'customerRequestMoreTime',
        summary: 'Ask for more time to bring the piece (seller)',
        description: 'Spec 014 FR-022. While the order waits for delivery and before the reach-branch deadline: a reason and a line — Dahab chooses the time (6, 12, 24 or 48 working hours) or refuses, and you are told by SMS and email. The deadline keeps running meanwhile; one request at a time. If you already sold it elsewhere, cancel the sale instead (POST …/cancel). Audited (order.extension_requested). Verified customers. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RequestMoreTimeRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The order, with extension_request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your sale)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | deadline_not_running | extension_request_pending | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function requestMoreTime(RequestMoreTimeRequest $request, string $order, RequestMoreTimeAction $ask, ListOwnOrdersAction $list): JsonResponse
    {
        $ask->handle($this->customer($request), $order, $request->reason(), trim((string) $request->validated('detail')), $request->attributes->get('context'));

        return $this->respond($request, $order, $list, 201);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/proxy',
        operationId: 'customerNameProxy',
        summary: 'Name someone else to collect (buyer)',
        description: 'Spec 014 FR-018. On a ready-to-collect order not yet collected: their full name as on their ID, their phone, a photo of the front of their ID (upload purpose proxy_id), and your acceptance of the current collection_proxy_authorisation. Dahab does not verify the relationship. They get one SMS telling them where to go and to bring their ID — never the code: share your collection code with them yourself. Naming again replaces them. Audited (order.proxy_named). Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NameProxyRequest')),
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order, with proxy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found (not your purchase)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'declaration_required | upload_token_invalid | validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function nameProxy(NameProxyRequest $request, string $order, NameProxyAction $name, ListOwnOrdersAction $list): JsonResponse
    {
        $name->handle($this->customer($request), $order, (string) $request->validated('name'), (string) $request->validated('phone'),
            (string) $request->validated('id_upload_token'), (int) $request->validated('authorisation_id'), $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    #[OA\Post(
        path: '/customer/me/orders/{order}/proxy/remove',
        operationId: 'customerRemoveProxy',
        summary: 'Remove the person named to collect (buyer)',
        description: 'Spec 014 FR-018. Before collection: only you can collect again. Audited (order.proxy_removed). Verified customers. Idempotent.',
        security: [['customerBearer' => []]],
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
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_order_transition (none named, or collected) | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function removeProxy(Request $request, string $order, RemoveProxyAction $remove, ListOwnOrdersAction $list): JsonResponse
    {
        $remove->handle($this->customer($request), $order, $request->attributes->get('context'));

        return $this->respond($request, $order, $list);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }

    private function respond(Request $request, string $orderId, ListOwnOrdersAction $list, int $status = 200): JsonResponse
    {
        return response()->json(['data' => CustomerOrderResource::make($list->show($orderId))->withCode()->resolve($request)], $status);
    }
}
