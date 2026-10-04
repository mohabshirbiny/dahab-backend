<?php

namespace App\Http\Resources\Customer;

use App\Enums\BuyRequestState;
use App\Enums\ListingMediaKind;
use App\Http\Resources\ListingMediaResource;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Support\Wallet\HeldByRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One of the buyer's own requests (spec 011 FR-009). Never contains the
 * seller. `listing` is a summary of the piece, in whatever state it is now.
 */
#[OA\Schema(
    schema: 'BuyRequest',
    description: 'A buyer\'s own request (spec 011). Money as 4-dp strings. `place_in_line` (1 = the seller answers you next) and `ahead_count` are set only while queued. `order` is set once accepted. Never contains the seller.',
    required: ['id', 'state', 'listing', 'queue_position', 'place_in_line', 'ahead_count', 'locked_unit_rate', 'locked_total_price', 'deposit_amount', 'deposit_held', 'requested_at', 'seller_reply_deadline', 'resolved_at', 'notify_when_free', 'order'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', enum: ['queued', 'accepted', 'released_not_chosen', 'released_declined', 'released_expired', 'withdrawn_by_buyer']),
        new OA\Property(property: 'listing', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'category', type: 'string'),
            new OA\Property(property: 'piece_type', properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name_en', type: 'string'),
                new OA\Property(property: 'name_ar', type: 'string'),
            ], type: 'object'),
            new OA\Property(property: 'karat', type: 'integer', nullable: true),
            new OA\Property(property: 'weight_g', type: 'string', nullable: true),
            new OA\Property(property: 'state', type: 'string', description: 'The listing state now (a listing_state value)'),
            new OA\Property(property: 'queue_count', type: 'integer'),
            new OA\Property(property: 'photo', ref: '#/components/schemas/ListingMedia', nullable: true, description: 'The first public photo; its url is on the public market and works only while the piece is on the market'),
        ], type: 'object'),
        new OA\Property(property: 'queue_position', type: 'integer', description: 'Arrival number on this piece (not the place in line)'),
        new OA\Property(property: 'place_in_line', type: 'integer', nullable: true),
        new OA\Property(property: 'ahead_count', type: 'integer', nullable: true),
        new OA\Property(property: 'locked_unit_rate', type: 'string', nullable: true, description: 'The karat\'s rate per gram at the moment of the request; null for a pure diamond'),
        new OA\Property(property: 'locked_total_price', type: 'string', example: '58200.0000'),
        new OA\Property(property: 'deposit_amount', type: 'string', example: '11640.0000'),
        new OA\Property(property: 'deposit_held', type: 'string', example: '11640.0000', description: 'Spec 015: what this request holds in your wallet now, from the ledger (0 once released, forfeited or paid towards the price)'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'seller_reply_deadline', type: 'string', format: 'date-time'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notify_when_free', type: 'boolean'),
        new OA\Property(property: 'order', ref: '#/components/schemas/OrderSummary', nullable: true),
    ],
)]
class BuyRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BuyRequest $r */
        $r = $this->resource;
        $ahead = $r->state === BuyRequestState::QUEUED ? $r->getAttribute('ahead_count') : null;

        return [
            'id' => $r->buy_request_id,
            'state' => $r->state->value,
            'listing' => self::listing($r->relationLoaded('listing') ? $r->listing : null),
            'queue_position' => $r->queue_position,
            'place_in_line' => $ahead === null ? null : (int) $ahead + 1,
            'ahead_count' => $ahead === null ? null : (int) $ahead,
            'locked_unit_rate' => $r->locked_unit_rate === null ? null : bcadd((string) $r->locked_unit_rate, '0', 4),
            'locked_total_price' => bcadd((string) $r->locked_total_price, '0', 4),
            'deposit_amount' => bcadd((string) $r->deposit_amount, '0', 4),
            'deposit_held' => app(HeldByRequest::class)->of($r->buy_request_id),
            'requested_at' => $r->requested_at->toIso8601String(),
            'seller_reply_deadline' => $r->seller_reply_deadline->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'notify_when_free' => $r->notify_when_free,
            'order' => OrderSummaryResource::shape($r->relationLoaded('order') ? $r->order : null),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function listing(?Listing $l): ?array
    {
        if ($l === null) {
            return null;
        }

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
            'state' => $l->state->value,
            'queue_count' => $l->active_queue_count,
            'photo' => $l->relationLoaded('photos')
                ? ListingMediaResource::first($l->photos->where('is_private', false), ListingMediaKind::PHOTO, ListingMediaResource::MARKET)
                : null,
        ];
    }
}
