<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\DomainApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Market\ListMarketListingsRequest;
use App\Http\Resources\Market\MarketListingResource;
use App\Models\MarketMakerApproval;
use App\Models\PromoCode;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\Listings\MarketQuery;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The market as a market maker sees it (spec 019 FR-064): the same pieces as the public
 * market, each marked when Dahab has approved it for market makers, with a filter. Only a
 * customer whose account holds an active code is answered; the public market is unchanged.
 */
class MarketMakerListingController extends Controller
{
    #[OA\Get(
        path: '/customer/me/market/listings',
        operationId: 'customerMarketMakerListings',
        summary: 'The market, with approved pieces marked',
        description: 'Spec 019 FR-064 (6B). The public market list plus `mm_approved` on each piece; `mm_approved=1` keeps only approved ones. 403 market_maker_only for an account with no active code. Same filters, sort and paging as /market/listings.',
        security: [['customerBearer' => []]],
        tags: ['Customer Market makers'],
        responses: [
            new OA\Response(response: 200, description: 'A page of live pieces'),
            new OA\Response(response: 403, description: 'market_maker_only', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListMarketListingsRequest $request): JsonResponse
    {
        $customer = $request->user('customer');
        $hasCode = DatabaseActor::queue(fn () => PromoCode::query()
            ->where('tied_customer_id', $customer->customer_id)->where('is_active', true)->exists());
        if (! $hasCode) {
            throw DomainApiException::marketMakerOnly();
        }

        $perPage = $request->perPage();
        $filters = $request->filters() + ['mm_approved' => $request->boolean('mm_approved')];

        return DatabaseActor::market(function () use ($request, $filters, $perPage) {
            $page = (new MarketQuery(ListingPricer::for($request)))->page($filters, $request->sort(), $request->cursor(), $perPage);
            $approved = MarketMakerApproval::query()->whereIn('listing_id', $page['rows']->pluck('listing_id'))->pluck('listing_id')->flip();

            $data = MarketListingResource::collection($page['rows'])->resolve($request);
            foreach ($data as $i => $row) {
                $data[$i]['mm_approved'] = $approved->has($page['rows'][$i]->listing_id);
            }

            return response()->json([
                'data' => $data,
                'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
            ]);
        });
    }
}
