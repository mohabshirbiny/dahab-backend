<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Listings\CreateListingAction;
use App\Actions\Listings\ListOwnListingsAction;
use App\Actions\Listings\ReadListingMediaAction;
use App\Actions\Listings\SubmitListingAction;
use App\Actions\Listings\UpdateListingAction;
use App\Actions\Listings\WithdrawListingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Listing\StoreListingRequest;
use App\Http\Requests\Customer\Listing\UpdateListingRequest;
use App\Http\Requests\ListListingsRequest;
use App\Http\Resources\Customer\ListingResource;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Selling a piece, seller side (spec 010 US1/US4; Part 2 §3). A customer
 * reaches only their own listings (forced row-level security). Reading needs
 * a verified customer; every write needs the trade gate (verified and not
 * suspended) and an Idempotency-Key.
 */
class ListingController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/listings',
        operationId: 'customerListings',
        summary: 'The seller\'s own listings',
        description: 'Spec 010 FR-018. Newest first, keyset-paginated, optionally one state. Verified customers; a suspended customer may read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'live')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of listings', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerListing')),
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
    public function index(ListListingsRequest $request, ListOwnListingsAction $list): JsonResponse
    {
        $perPage = $request->perPage(20);
        $page = $list->handle($this->customer($request)->customer_id, $request->state(), $request->cursor(), $perPage);

        return response()->json([
            'data' => ListingResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/listings/{listing}',
        operationId: 'customerListing',
        summary: 'One of the seller\'s own listings',
        description: 'Spec 010 FR-018. 404 for a listing that is not the caller\'s.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $listing, ListOwnListingsAction $list): JsonResponse
    {
        return $this->respond($request, $list->show($this->customer($request)->customer_id, $listing));
    }

    #[OA\Get(
        path: '/customer/me/listings/{listing}/media/{media}',
        operationId: 'customerListingMedia',
        summary: 'A file of one of the seller\'s own listings',
        description: 'Spec 010 FR-010. The decrypted file, streamed, the private invoice included; Cache-Control: no-store. 404 for anyone else\'s listing.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'media', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function media(Request $request, string $listing, string $media, ReadListingMediaAction $read): StreamedResponse
    {
        return $read->handle($listing, $media, sellerId: $this->customer($request)->customer_id);
    }

    #[OA\Post(
        path: '/customer/me/listings',
        operationId: 'customerCreateListing',
        summary: 'List a piece (creates a draft)',
        description: 'Spec 010 FR-001–FR-005, Part 2 §3. Creates a draft with its media (from upload tokens), its branch options, the accepted ownership declaration and its queue counter, in one transaction. It is not on the market: send it for review with /submit. Verified, non-suspended customers only (trade gate). Idempotent: requires an Idempotency-Key header.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreListingRequest')),
        tags: ['Customer Listings'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'The draft', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'gold_needs_karat_weight | branch_options_required | ownership_declaration_required (not accepted, or not the current version) | upload_token_invalid | validation_failed (fields of the category, media limits) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(StoreListingRequest $request, CreateListingAction $create): JsonResponse
    {
        $listing = $create->handle($this->customer($request), $request->validated(), $request->attributes->get('context'));

        return $this->respond($request, $listing, 201);
    }

    #[OA\Patch(
        path: '/customer/me/listings/{listing}',
        operationId: 'customerUpdateListing',
        summary: 'Edit a draft or a listing sent back for changes',
        description: 'Spec 010 FR-006, Part 2 §3. Any field but the category, the branch set and the media. Only while the listing is a draft or changes were requested. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateListingRequest')),
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'listing_not_editable | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'gold_needs_karat_weight | branch_options_required | upload_token_invalid | validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function update(UpdateListingRequest $request, string $listing, UpdateListingAction $update): JsonResponse
    {
        return $this->respond($request, $update->handle($this->customer($request), $listing, $request->validated()));
    }

    #[OA\Post(
        path: '/customer/me/listings/{listing}/submit',
        operationId: 'customerSubmitListing',
        summary: 'Send a listing for review',
        description: 'Spec 010 FR-013, Part 2 §3. draft | changes_requested → in_review. Needs at least 2 photos (gold) or 3 (diamond, gold_with_diamond), a description of 40 to 2000 characters, and a karat, piece type and at least one branch that are still enabled. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing, in review', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (not a draft or changes requested) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'photo_required | branch_options_required | validation_failed (description, karat or piece type turned off) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function submit(Request $request, string $listing, SubmitListingAction $submit): JsonResponse
    {
        return $this->respond($request, $submit->handle($this->customer($request), $listing));
    }

    #[OA\Post(
        path: '/customer/me/listings/{listing}/withdraw',
        operationId: 'customerWithdrawListing',
        summary: 'Take a live listing off the market',
        description: 'Spec 010 FR-014, Part 2 §3. live → withdrawn, at once. Final: a withdrawn piece is sold again only as a new listing. From live only (withdrawing a reserved listing comes with buy requests). Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawn listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (not live) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function withdraw(Request $request, string $listing, WithdrawListingAction $withdraw): JsonResponse
    {
        return $this->respond($request, $withdraw->handle($this->customer($request), $listing));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }

    private function respond(Request $request, Listing $listing, int $status = 200): JsonResponse
    {
        $listing->load(ListOwnListingsAction::RELATIONS);

        return response()->json(['data' => ListingResource::make($listing)->resolve($request)], $status);
    }
}
