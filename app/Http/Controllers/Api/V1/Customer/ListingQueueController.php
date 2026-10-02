<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\DeclineBuyRequestAction;
use App\Actions\BuyRequests\ShowQueueAction;
use App\Actions\Listings\ListOwnListingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BuyRequest\AnswerBuyRequestRequest;
use App\Http\Resources\Customer\ListingResource;
use App\Http\Resources\Customer\OrderSummaryResource;
use App\Http\Resources\Customer\SellerQueueItemResource;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Support\Listings\ListingPricer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Buying, seller side (spec 011 US3; Part 2 §4 "the seller's view of the
 * queue", §5 acceptance). Only the listing's seller reaches its queue (404
 * otherwise). Reading needs a verified customer; accepting and declining the
 * trade gate and an Idempotency-Key.
 */
class ListingQueueController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/listings/{listing}/buy-requests',
        operationId: 'customerListingQueue',
        summary: 'The buy requests on my listing',
        description: 'Spec 011 FR-013. The queued requests in line order, each buyer by display reference only; the whole line (not paginated). meta.you_would_receive is what you would receive if the piece sold now (indicative; the seller\'s side is not locked per request). 404 for a listing that is not yours. Verified customers.',
        security: [['customerBearer' => []]],
        tags: ['Customer Buy Requests'],
        parameters: [new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The line', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SellerQueueItem')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'queue_count', type: 'integer'),
                    new OA\Property(property: 'you_would_receive', type: 'string', nullable: true),
                    new OA\Property(property: 'price_is_indicative', type: 'boolean'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, string $listing, ShowQueueAction $show): JsonResponse
    {
        $found = $show->handle($this->customer($request)->customer_id, $listing);
        $queue = $found['queue']->values()->each(fn (BuyRequest $r, int $i) => $r->setAttribute('place_in_line', $i + 1));
        $quote = ListingPricer::for($request)->quote($found['listing']);

        return response()->json([
            'data' => SellerQueueItemResource::collection($queue)->resolve($request),
            'meta' => [
                'queue_count' => $queue->count(),
                'you_would_receive' => $quote->youWouldReceive,
                'price_is_indicative' => $quote->priceIsIndicative,
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/me/listings/{listing}/accept',
        operationId: 'customerAcceptBuyRequest',
        summary: 'Accept the first buyer in line',
        description: 'Spec 011 FR-014, Part 2 §5. Only the head of the queue, at one of the listing\'s enabled branch options. One transaction: the order is created (order_ref DH-YYYY-NNNNNN, awaiting_delivery, reach_branch_deadline counted in working hours at that branch from deadline.reach_branch_working_hours); the head becomes accepted and keeps its deposit held; everyone else in line is released (released_not_chosen) and refunded; the piece moves reserved → accepted and leaves the market. Buyers are told after commit. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AcceptBuyRequestRequest')),
        tags: ['Customer Buy Requests'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The order and the listing', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'order', ref: '#/components/schemas/OrderSummary'),
                    new OA\Property(property: 'listing', ref: '#/components/schemas/CustomerListing'),
                    new OA\Property(property: 'released_count', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'not_queue_head | queue_empty | branch_not_in_options | buyer_suspended | branch_hours_unavailable | illegal_listing_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function accept(AnswerBuyRequestRequest $request, string $listing, AcceptBuyRequestAction $accept, ListOwnListingsAction $own): JsonResponse
    {
        $seller = $this->customer($request);
        $done = $accept->handle($seller, $listing, (string) $request->validated('buy_request_id'), (int) $request->validated('branch_id'));

        return response()->json(['data' => [
            'order' => OrderSummaryResource::shape($done['order']),
            'listing' => ListingResource::make($own->show($seller->customer_id, $listing))->resolve($request),
            'released_count' => $done['released_count'],
        ]], 201);
    }

    #[OA\Post(
        path: '/customer/me/listings/{listing}/decline',
        operationId: 'customerDeclineBuyRequest',
        summary: 'Decline the first buyer in line',
        description: 'Spec 011 FR-015. Only the head of the queue, no reason asked. The buyer is released (released_declined) and refunded; the next request becomes the head; with nobody left the piece is live again. The buyer is told after commit. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeclineBuyRequestRequest')),
        tags: ['Customer Buy Requests'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing (reserved or live again)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'not_queue_head | queue_empty | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function decline(AnswerBuyRequestRequest $request, string $listing, DeclineBuyRequestAction $decline, ListOwnListingsAction $own): JsonResponse
    {
        $seller = $this->customer($request);
        $decline->handle($seller, $listing, (string) $request->validated('buy_request_id'));

        return response()->json(['data' => ListingResource::make($own->show($seller->customer_id, $listing))->resolve($request)]);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
