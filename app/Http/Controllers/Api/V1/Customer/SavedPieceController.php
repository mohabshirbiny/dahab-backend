<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Saved\SavedPiecesAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\UseMarketScope;
use App\Http\Resources\Market\MarketListingResource;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SavedListing;
use App\Support\ApiResponse;
use App\Support\DatabaseActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Saved pieces (spec 017 US5, FR-040). Every signed-in customer. A piece on the
 * market comes back in the market's shape with its indicative price; a piece
 * that left the market comes back as `available: false` with its summary only.
 */
#[OA\Schema(
    schema: 'SavedPiece',
    required: ['listing_id', 'saved_at', 'available', 'listing', 'summary'],
    properties: [
        new OA\Property(property: 'listing_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'saved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'available', type: 'boolean', description: 'Still on the market (live or reserved)'),
        new OA\Property(property: 'listing', ref: '#/components/schemas/MarketListing', nullable: true, description: 'Only while available'),
        new OA\Property(property: 'summary', properties: [
            new OA\Property(property: 'category', type: 'string'),
            new OA\Property(property: 'piece_type', type: 'object'),
            new OA\Property(property: 'karat', type: 'integer', nullable: true),
            new OA\Property(property: 'weight_g', type: 'string', nullable: true),
        ], type: 'object', description: 'Taken when saved; no photos or price'),
    ],
)]
class SavedPieceController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/saved-pieces',
        operationId: 'customerSavedPieces',
        summary: 'The pieces the customer saved, newest first',
        description: 'Spec 017 FR-040. Up to saved.max_per_customer rows, unpaged; `listing_id` checks one piece (for its Save button).',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'listing_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Saved pieces', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SavedPiece')),
        ]))],
    )]
    public function index(Request $request, SavedPiecesAction $saved): JsonResponse
    {
        $request->validate(['listing_id' => ['sometimes', 'uuid']]);
        $view = $saved->list($this->customer($request), $request->query('listing_id'));

        return ApiResponse::ok($this->render($request, $view['rows']->all(), $view['listings']->all()));
    }

    #[OA\Post(
        path: '/customer/me/saved-pieces',
        operationId: 'customerSavePiece',
        summary: 'Save a piece on the market',
        description: 'Spec 017 FR-040. Idempotent: saving a saved piece answers it again. listing_not_saveable when the piece is not live or reserved; saved_limit_reached at saved.max_per_customer.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['listing_id'], properties: [new OA\Property(property: 'listing_id', type: 'string', format: 'uuid')])),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SavedPiece')])),
            new OA\Response(response: 409, description: 'account_closed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | listing_not_saveable | saved_limit_reached (details.limit)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(Request $request, SavedPiecesAction $saved): JsonResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'uuid']]);
        $customer = $this->customer($request);
        $saved->save($customer, $data['listing_id']);
        $view = $saved->list($customer, $data['listing_id']);

        return ApiResponse::ok($this->render($request, $view['rows']->all(), $view['listings']->all())[0], 201);
    }

    #[OA\Delete(
        path: '/customer/me/saved-pieces/{listing}',
        operationId: 'customerUnsavePiece',
        summary: 'Remove a piece from Saved',
        description: 'Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Removed')],
    )]
    public function destroy(Request $request, string $listing, SavedPiecesAction $saved): Response
    {
        $saved->unsave($this->customer($request), $listing);

        return response()->noContent();
    }

    /**
     * @param  list<SavedListing>  $rows
     * @param  array<string, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    private function render(Request $request, array $rows, array $listings): array
    {
        $request->attributes->set(UseMarketScope::VIEWER, $this->customer($request)->customer_id);

        return DatabaseActor::market(fn () => array_map(function (SavedListing $row) use ($request, $listings) {
            $listing = $listings[$row->listing_id] ?? null;

            return [
                'listing_id' => $row->listing_id,
                'saved_at' => $row->saved_at->toIso8601String(),
                'available' => $listing !== null,
                'listing' => $listing === null ? null : (new MarketListingResource($listing))->resolve($request),
                'summary' => $row->summary,
            ];
        }, $rows));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
