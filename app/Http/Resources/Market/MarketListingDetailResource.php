<?php

namespace App\Http\Resources\Market;

use App\Enums\ListingMediaKind;
use App\Http\Resources\ListingMediaResource;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The public piece page (spec 010 FR-022): the market item plus the
 * description, the video, the stone certificate (public once live; only the
 * invoice is private) and, for gold, the parts of the price. No seller field
 * — see MarketListingResource.
 */
#[OA\Schema(
    schema: 'MarketListingDetail',
    description: 'A live piece in full (spec 010): MarketListing plus the description, the video, the stone certificate and, for gold, the parts of the price. No seller information.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/MarketListing'),
        new OA\Schema(
            required: ['description', 'video', 'stone_certificate', 'price_parts'],
            properties: [
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'video', ref: '#/components/schemas/ListingMedia', nullable: true),
                new OA\Property(property: 'stone_certificate', ref: '#/components/schemas/ListingMedia', nullable: true),
                new OA\Property(property: 'price_parts', nullable: true, description: 'Gold only, and only while a price can be quoted', properties: [
                    new OA\Property(property: 'rate_per_gram', type: 'string', example: '6975.0000', description: 'What buyers pay per gram of this karat now'),
                    new OA\Property(property: 'gold_value', type: 'string', example: '55800.0000'),
                    new OA\Property(property: 'making_total', type: 'string', example: '2000.0000'),
                ], type: 'object'),
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
        ];
    }
}
