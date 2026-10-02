<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Listings\DecideListingAction;
use App\Actions\Listings\ListListingsForReviewAction;
use App\Actions\Listings\ReadListingMediaAction;
use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Listing\DecideListingRequest;
use App\Http\Requests\ListListingsRequest;
use App\Http\Resources\Staff\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listings to review (spec 010 US2/US5; Part 2 §3 staff review; Part 1 §4.1).
 * Reading the queue, a listing and its media needs any listing permission;
 * approve and reject need `listing.review`, asking for changes needs
 * `listing.request_changes`, taking down needs `listing.takedown` (CEO, COO
 * and Operations by default). Every decision is idempotent, audited under the
 * staff member's name, recorded in the listing history and told to the
 * seller after commit.
 */
class ListingController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/listings',
        operationId: 'dashboardListings',
        summary: 'The listing review queue',
        description: 'Spec 010 FR-027. Listings of every seller in one state (default in_review, oldest first; other states most recent first), keyset-paginated. meta.counts has the numbers for the screen\'s chips: in_review, changes_requested, approved_today (Cairo day), rejected, live. Requires any of listing.review, listing.request_changes, listing.takedown.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'in_review')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of listings', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardListing')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'counts', properties: [
                        new OA\Property(property: 'in_review', type: 'integer'),
                        new OA\Property(property: 'changes_requested', type: 'integer'),
                        new OA\Property(property: 'approved_today', type: 'integer'),
                        new OA\Property(property: 'rejected', type: 'integer'),
                        new OA\Property(property: 'live', type: 'integer'),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListListingsRequest $request, ListListingsForReviewAction $list): JsonResponse
    {
        $perPage = $request->perPage(25);
        $page = $list->handle($request->state() ?? ListingState::IN_REVIEW, $request->cursor(), $perPage);

        return response()->json([
            'data' => ListingResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'counts' => $page['counts']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/listings/{listing}',
        operationId: 'dashboardListing',
        summary: 'One listing, for review',
        description: 'Spec 010 FR-027. Every field, every media item, the seller, the history and the "sent back" counters. Requires any listing permission.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Listings'],
        parameters: [new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DashboardListing')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $listing, ListListingsForReviewAction $list): JsonResponse
    {
        return $this->detail($request, $listing, $list);
    }

    #[OA\Get(
        path: '/dashboard/listings/{listing}/media/{media}',
        operationId: 'dashboardListingMedia',
        summary: 'A file of a listing, for review',
        description: 'Spec 010 FR-010, FR-027. The decrypted file, streamed, private ones included ("Buyers never see this"); Cache-Control: no-store. Requires any listing permission.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'media', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function media(string $listing, string $media, ReadListingMediaAction $read): StreamedResponse
    {
        return $read->handle($listing, $media);
    }

    #[OA\Post(
        path: '/dashboard/listings/{listing}/approve',
        operationId: 'dashboardApproveListing',
        summary: 'Approve and publish',
        description: 'Spec 010 FR-028, Part 2 §3. in_review → live; sets listed_at; the piece is on the market at once. Refused while the seller is suspended (seller_suspended) or when the listing\'s karat has been turned off since it was submitted (karat_disabled) — the listing stays in review. Audited (listing.approved); the seller gets an SMS/email after commit. Idempotent. Requires listing.review.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The live listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DashboardListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (not in review, or a lost race) | seller_suspended | karat_disabled | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function approve(Request $request, string $listing, DecideListingAction $decide, ListListingsForReviewAction $list): JsonResponse
    {
        $decide->handle($request->user('staff'), $listing, ListingDecision::APPROVED, null, $request->attributes->get('context'));

        return $this->detail($request, $listing, $list);
    }

    #[OA\Post(
        path: '/dashboard/listings/{listing}/request-changes',
        operationId: 'dashboardRequestListingChanges',
        summary: 'Ask the seller for changes',
        description: 'Spec 010 FR-029, Part 2 §3. in_review → changes_requested with a message the seller reads (e.g. a clearer hallmark photo). Audited (listing.changes_requested, reason = the message); the seller gets the message by SMS/email after commit. Idempotent. Requires listing.request_changes.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RequestListingChangesRequest')),
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing, sent back', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DashboardListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed (message 10–1000 characters) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function requestChanges(DecideListingRequest $request, string $listing, DecideListingAction $decide, ListListingsForReviewAction $list): JsonResponse
    {
        $decide->handle($request->user('staff'), $listing, ListingDecision::CHANGES_REQUESTED, $request->note(), $request->attributes->get('context'));

        return $this->detail($request, $listing, $list);
    }

    #[OA\Post(
        path: '/dashboard/listings/{listing}/reject',
        operationId: 'dashboardRejectListing',
        summary: 'Reject a listing',
        description: 'Spec 010 FR-030a. in_review → rejected with a reason the seller reads. Final: a rejected listing cannot be edited, resubmitted or approved. Audited (listing.rejected); the seller gets the reason by SMS/email after commit. Idempotent. Requires listing.review.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ListingReasonRequest')),
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The rejected listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DashboardListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed (reason 10–1000 characters) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function reject(DecideListingRequest $request, string $listing, DecideListingAction $decide, ListListingsForReviewAction $list): JsonResponse
    {
        $decide->handle($request->user('staff'), $listing, ListingDecision::REJECTED, $request->note(), $request->attributes->get('context'));

        return $this->detail($request, $listing, $list);
    }

    #[OA\Post(
        path: '/dashboard/listings/{listing}/takedown',
        operationId: 'dashboardTakeDownListing',
        summary: 'Take a live or reserved listing down',
        description: 'Spec 010 FR-030, Part 2 §3; spec 011 FR-019. live or reserved → withdrawn with a reason the seller reads; off the market at once. Final. From reserved, every buyer in line is released (released_declined) and refunded in the same transaction and told the piece was withdrawn; the audit row (listing.taken_down) carries released_count. The seller gets the reason by SMS/email after commit. Idempotent. Requires listing.takedown.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ListingReasonRequest')),
        tags: ['Dashboard Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawn listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DashboardListing')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_listing_transition (not live or reserved) | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed (reason 10–1000 characters) | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function takedown(DecideListingRequest $request, string $listing, DecideListingAction $decide, ListListingsForReviewAction $list): JsonResponse
    {
        $decide->handle($request->user('staff'), $listing, ListingDecision::TAKEN_DOWN, $request->note(), $request->attributes->get('context'));

        return $this->detail($request, $listing, $list);
    }

    /** The detail shape: the list item plus the history and the counters. */
    private function detail(Request $request, string $listingId, ListListingsForReviewAction $list): JsonResponse
    {
        $found = $list->show($listingId);
        /** @var Listing $listing */
        $listing = $found['listing'];

        return response()->json(['data' => ListingResource::make($listing)->resolve($request)
            + ['history' => ListingResource::history($listing)]
            + $found['counters'],
        ]);
    }
}
