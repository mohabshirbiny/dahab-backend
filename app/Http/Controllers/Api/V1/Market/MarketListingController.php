<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Actions\Listings\ReadListingMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Market\ListMarketListingsRequest;
use App\Http\Resources\Market\MarketListingDetailResource;
use App\Http\Resources\Market\MarketListingResource;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use App\Support\Listings\MarketQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public market (spec 010 US3; Part 2 §2): anyone, without an account,
 * browses the live pieces. Every route here runs in the read-only `market`
 * database scope (`db.market`) and returns only the market Resources, which
 * have no seller field (Part 1 §5.3 — no database view; MarketLeakTest
 * guards it). A customer token is optional and only sets `is_mine`.
 */
class MarketListingController extends Controller
{
    #[OA\Get(
        path: '/market/listings',
        operationId: 'marketListings',
        summary: 'Browse the live pieces',
        description: 'Spec 010 FR-020–FR-023, Part 2 §2. Public: no token needed (a customer access token is optional and only sets is_mine). Listings that are live (or reserved), with filters, three sorts and keyset pages. current_price is computed now from the gold price — indicative for gold until a buy request locks it — and is null with price_available=false when it cannot be quoted; such pieces sort last in price order. A cursor only positions the page: prices are recomputed for each page. Never returns the seller or any private media.',
        tags: ['Market'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond'])),
            new OA\Parameter(name: 'karat', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 21)),
            new OA\Parameter(name: 'piece_type', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch', in: 'query', required: false, description: 'Pieces that can be inspected at this branch', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'min_g', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '5')),
            new OA\Parameter(name: 'max_g', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '20')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['newest', 'price_asc', 'price_desc'], default: 'newest')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of live pieces', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketListing')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'validation_failed (a filter, the sort or the cursor)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListMarketListingsRequest $request): JsonResponse
    {
        $perPage = $request->perPage();
        $page = (new MarketQuery(ListingPricer::for($request)))
            ->page($request->filters(), $request->sort(), $request->cursor(), $perPage);

        return response()->json([
            'data' => MarketListingResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/market/listings/{listing}',
        operationId: 'marketListing',
        summary: 'One live piece',
        description: 'Spec 010 FR-022, FR-024. Public. The market item plus the description, the video, the stone certificate (public once live) and, for gold, the parts of the price. 404 for a listing that is not on the market — a draft, one in review, withdrawn, rejected or on hold — whatever its id.',
        tags: ['Market'],
        parameters: [new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The piece', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketListingDetail')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $listing): JsonResponse
    {
        $model = Listing::query()->publiclyVisible()->with(['pieceType', 'media', 'branches'])->findOrFail($listing);

        return response()->json(['data' => MarketListingDetailResource::make($model)->resolve($request)]);
    }

    #[OA\Get(
        path: '/market/listings/{listing}/media/{media}',
        operationId: 'marketListingMedia',
        summary: 'A public photo, video or certificate of a live piece',
        description: 'Spec 010 FR-011. Public. The decrypted file, streamed, with its content type and Cache-Control: no-store — it stops being served the moment the listing leaves the market. 404 for the private invoice, for another listing\'s media, and for a listing that is not on the market.',
        tags: ['Market'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'media', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function media(string $listing, string $media, ReadListingMediaAction $read): StreamedResponse
    {
        return $read->handle($listing, $media, publicOnly: true);
    }
}
