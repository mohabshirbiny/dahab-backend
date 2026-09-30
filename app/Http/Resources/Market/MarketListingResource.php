<?php

namespace App\Http\Resources\Market;

use App\Enums\ListingMediaKind;
use App\Http\Middleware\UseMarketScope;
use App\Http\Resources\ListingMediaResource;
use App\Models\Branch;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A live piece as the public market shows it (spec 010 FR-022, Part 2 §2).
 *
 * THIS SHAPE HAS NO SELLER FIELD, and must never get one: the market has no
 * database view to hide the seller, so this Resource is the column boundary
 * (Part 1 §5.3, product-owner decision). `seller_id` is read once, to tell a
 * signed-in customer which pieces are their own, and is never serialised.
 * MarketLeakTest fails the build if a seller or private field appears here.
 */
#[OA\Schema(
    schema: 'MarketListing',
    description: 'A live piece on the public market (spec 010). Carries no seller information of any kind and no private media. `current_price` is what a buyer would pay now: for gold it follows the gold price and is indicative until a buy request locks it; for diamond and gold-with-diamond it is the seller\'s asking price.',
    required: ['id', 'category', 'piece_type', 'karat', 'weight_g', 'making_charge_per_g', 'current_price', 'price_available', 'price_is_indicative', 'photos', 'branch_options', 'queue_count', 'listed_at', 'is_mine'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
        new OA\Property(property: 'piece_type', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name_en', type: 'string', example: 'Ring'),
            new OA\Property(property: 'name_ar', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'karat', type: 'integer', nullable: true, example: 21, description: 'Null for a pure diamond'),
        new OA\Property(property: 'weight_g', type: 'string', nullable: true, example: '8.000', description: 'Stated by the seller, 3 decimals; null for a pure diamond'),
        new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true, example: '250.0000', description: 'Gold only'),
        new OA\Property(property: 'current_price', type: 'string', nullable: true, example: '58200.0000', description: 'Null when no price can be quoted right now (see price_available)'),
        new OA\Property(property: 'price_available', type: 'boolean'),
        new OA\Property(property: 'price_is_indicative', type: 'boolean', description: 'True for gold: the price moves with the gold rate until a buy request locks it'),
        new OA\Property(property: 'photos', type: 'array', items: new OA\Items(ref: '#/components/schemas/ListingMedia')),
        new OA\Property(property: 'branch_options', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name_en', type: 'string'),
            new OA\Property(property: 'name_ar', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'queue_count', type: 'integer', description: 'Buyers waiting; 0 until buy requests exist'),
        new OA\Property(property: 'listed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_mine', type: 'boolean', description: 'True only when a valid customer token was sent and the piece is that customer\'s'),
    ],
)]
class MarketListingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Listing $l */
        $l = $this->resource;
        $quote = ListingPricer::for($request)->quote($l);
        $viewer = $request->attributes->get(UseMarketScope::VIEWER);
        $public = ListingMediaResource::publicOf($l);

        return [
            'id' => $l->listing_id,
            'category' => $l->category->value,
            'piece_type' => [
                'id' => $l->piece_type_id,
                'name_en' => $l->pieceType?->name_en,
                'name_ar' => $l->pieceType?->name_ar,
            ],
            'karat' => $l->karat_code,
            'weight_g' => $l->stated_weight_g === null ? null : bcadd((string) $l->stated_weight_g, '0', 3),
            'making_charge_per_g' => $l->making_charge_per_g === null ? null : bcadd((string) $l->making_charge_per_g, '0', 4),
            'current_price' => $quote->currentPrice,
            'price_available' => $quote->priceAvailable(),
            'price_is_indicative' => $quote->priceIsIndicative,
            'photos' => ListingMediaResource::many($public->where('kind', ListingMediaKind::PHOTO), ListingMediaResource::MARKET),
            'branch_options' => $l->branches->map(fn (Branch $b) => [
                'id' => $b->branch_id,
                'name_en' => $b->name_en,
                'name_ar' => $b->name_ar,
            ])->values()->all(),
            'queue_count' => $l->active_queue_count,
            'listed_at' => $l->listed_at?->toIso8601String(),
            'is_mine' => is_string($viewer) && $viewer === $l->seller_id,
        ];
    }
}
