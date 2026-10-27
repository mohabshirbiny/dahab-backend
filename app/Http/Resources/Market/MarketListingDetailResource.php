<?php

namespace App\Http\Resources\Market;

use App\Enums\ListingMediaKind;
use App\Http\Resources\ListingMediaResource;
use App\Models\Listing;
use App\Support\BuyRequests\DepositRule;
use App\Support\Listings\ListingPricer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The public piece page (spec 010 FR-022): the market item plus the
 * description, the video, the stone certificate (public once live; only the
 * invoice is private) and, for gold, the parts of the price. No seller field
 * — see MarketListingResource — only how many sales the seller completed.
 */
#[OA\Schema(
    schema: 'MarketListingDetail',
    description: 'A live piece in full (spec 010): MarketListing plus the description, the video, the stone certificate, for gold the parts of the price, and (spec 011) the indicative deposit. No seller information beyond how many sales the seller completed.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/MarketListing'),
        new OA\Schema(
            required: ['description', 'video', 'stone_certificate', 'price_parts', 'deposit_amount', 'seller_sales_count'],
            properties: [
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'video', ref: '#/components/schemas/ListingMedia', nullable: true),
                new OA\Property(property: 'stone_certificate', ref: '#/components/schemas/ListingMedia', nullable: true),
                new OA\Property(property: 'price_parts', nullable: true, description: 'Gold only, and only while a price can be quoted', properties: [
                    new OA\Property(property: 'rate_per_gram', type: 'string', example: '6975.0000', description: 'What buyers pay per gram of this karat now'),
                    new OA\Property(property: 'gold_value', type: 'string', example: '55800.0000'),
                    new OA\Property(property: 'making_total', type: 'string', example: '2000.0000'),
                ], type: 'object'),
                new OA\Property(property: 'deposit_amount', type: 'string', nullable: true, example: '11640.0000', description: 'Spec 011 FR-002a: what a buy request would hold now (deposit.buyer_pct of current_price, half-up to the piastre). Indicative; null when there is no price.'),
                new OA\Property(property: 'seller_sales_count', type: 'integer', example: 3, description: 'Seller notes 2026-10-27: how many sales the seller of this piece has completed on Dahab (orders settled). A count only, never who.'),
            ],
        ),
    ],
)]
class MarketListingDetailResource extends MarketListingResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Listing $l */
        $l = $this->resource;
        $public = ListingMediaResource::publicOf($l);

        return parent::toArray($request) + [
            'description' => $l->description,
            'video' => ListingMediaResource::first($public, ListingMediaKind::VIDEO, ListingMediaResource::MARKET),
            'stone_certificate' => ListingMediaResource::first($public, ListingMediaKind::STONE_CERTIFICATE, ListingMediaResource::MARKET),
            'price_parts' => ListingPricer::for($request)->quote($l)->priceParts(),
            'deposit_amount' => ($price = ListingPricer::for($request)->quote($l)->currentPrice) === null
                ? null
                : app(DepositRule::class)->deposit($price),
            'seller_sales_count' => (int) DB::selectOne('SELECT seller_sales_count(?) AS n', [$l->listing_id])->n,
        ];
    }
}
