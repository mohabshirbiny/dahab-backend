<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Listings\ListOwnListingsAction;
use App\Actions\Promo\ApplyListingPromoAction;
use App\Actions\Promo\PreviewCommissionAction;
use App\Actions\Promo\RemoveListingPromoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Promo\ApplyListingPromoRequest;
use App\Http\Requests\Customer\Promo\CommissionPreviewRequest;
use App\Http\Resources\Customer\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Codes on the sell form (spec 023 Stories 2-4): what a code would do, and recording it on a listing.
 * Seller-side codes and referral are behind `promo.seller_codes_enabled` / `referral.enabled`
 * (default off): a closed programme answers `promo_code_invalid` with the reason `not_open`.
 */
class PromoController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/customer/me/commission-preview',
        operationId: 'customerCommissionPreview',
        summary: 'What a code would do to the commission',
        description: 'Spec 023 Story 2. Stateless: records nothing and spends nothing. The piece figures of the sell form (karat and weight_g for gold, making_charge_per_g for gold, asking_price for stones) and an optional `code` (a promo code or an invitation code). Answers the commission the lowest-single-waiver rule would charge now (the credits in the seller pool and the code, never a sum; the minimum commission floors a percentage or amount credit), what the seller would receive with and without it, and whether the first gold sale would be paid ahead. Figures are indicative. Package C: the rate and the minimum include VAT; commission.ordinary_egp, charged_egp and saved_egp are net of VAT, ordinary_total_egp, charged_total_egp and saved_total_egp are VAT included. Trade gate. 10 a minute.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
            new OA\Property(property: 'karat', type: 'integer', nullable: true, example: 21),
            new OA\Property(property: 'weight_g', type: 'string', nullable: true, example: '10.000'),
            new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true, example: '300.00'),
            new OA\Property(property: 'asking_price', type: 'string', nullable: true, example: '120000.00'),
            new OA\Property(property: 'code', type: 'string', nullable: true, example: 'WELCOME'),
        ])),
        tags: ['Customer Listings'],
        responses: [
            new OA\Response(response: 200, description: 'The effect of the code'),
            new OA\Response(response: 403, description: 'verification_required | account_suspended | account_closed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'promo_code_invalid (details.reason: unknown | not_open | not_started | ended | first_sale_only | already_used | cap_reached | own_referral | referral_joined | referral_full | device_limit) | validation_failed | price_unavailable', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function preview(CommissionPreviewRequest $request, PreviewCommissionAction $preview): JsonResponse
    {
        return response()->json(['data' => $preview->handle($request->user('customer'), $request->validated(), $request->attributes->get('context'))]);
    }

    #[OA\Put(
        path: '/customer/me/listings/{listing}/promo',
        operationId: 'customerApplyListingPromo',
        summary: 'Record a code on a listing',
        description: 'Spec 023 Story 2. Redeems a promo code or an invitation code for this listing in one transaction: the gate (switch, dates, first sale, use limit per national ID, monthly cap) under the code row lock, the allowed use, the credit bound to the listing. A code already on the listing is replaced (its credit voided). Possible while the listing is draft, changes requested, in review or live and no buyer is in line. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'code', type: 'string', example: 'WELCOME')])),
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'listing_promo_locked | platform_stopped | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'promo_code_invalid', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function apply(ApplyListingPromoRequest $request, string $listing, ApplyListingPromoAction $apply): JsonResponse
    {
        $result = $apply->handle($request->user('customer'), $listing, (string) $request->validated('code'), $request->attributes->get('context'));

        return $this->respond($request, $result);
    }

    #[OA\Delete(
        path: '/customer/me/listings/{listing}/promo',
        operationId: 'customerRemoveListingPromo',
        summary: 'Take the code off a listing',
        description: 'Spec 023 Story 2. Voids the credit and removes the code. Refused once a buyer is in line (listing_promo_locked); promo_not_applied when the listing has none. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [
            new OA\Parameter(name: 'listing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The listing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerListing')])),
            new OA\Response(response: 404, description: 'not_found | promo_not_applied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'listing_promo_locked', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function remove(Request $request, string $listing, RemoveListingPromoAction $remove): JsonResponse
    {
        $result = $remove->handle($request->user('customer'), $listing, $request->attributes->get('context'));

        return $this->respond($request, $result);
    }

    private function respond(Request $request, Listing $listing): JsonResponse
    {
        $listing->load(ListOwnListingsAction::RELATIONS);

        return response()->json(['data' => ListingResource::make($listing)->resolve($request)]);
    }
}
