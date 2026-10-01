<?php

namespace App\Http\Resources\Customer;

use App\Enums\ListingState;
use App\Http\Resources\ListingMediaResource;
use App\Models\Branch;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Support\Listings\ListingPricer;
use App\Support\Listings\ListingQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerListing',
    description: 'One of the seller\'s own listings (spec 010). `staff_message` is what Dahab wrote when it asked for changes, rejected the piece or took it down. `current_price` is what a buyer would pay now and `you_would_receive` what would reach the seller after commission and VAT; both are indicative, recomputed on every read, and null when no price can be quoted.',
    required: ['id', 'state', 'category', 'piece_type', 'karat', 'stated_weight_g', 'making_charge_per_g', 'asking_price', 'description', 'media', 'branch_options', 'current_price', 'price_available', 'price_is_indicative', 'you_would_receive', 'staff_message', 'staff_message_at', 'created_at', 'listed_at', 'state_changed_at', 'can_edit', 'can_submit', 'can_withdraw', 'queue_count', 'order'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', description: 'A listing_state value; clients must tolerate ones they do not know', example: 'changes_requested'),
        new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
        new OA\Property(property: 'piece_type', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name_en', type: 'string'),
            new OA\Property(property: 'name_ar', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'karat', type: 'integer', nullable: true),
        new OA\Property(property: 'stated_weight_g', type: 'string', nullable: true, example: '8.000'),
        new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true, example: '250.0000'),
        new OA\Property(property: 'asking_price', type: 'string', nullable: true, example: '120000.0000'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'media', type: 'array', items: new OA\Items(ref: '#/components/schemas/ListingMedia'), description: 'Everything attached, the private invoice included'),
        new OA\Property(property: 'branch_options', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name_en', type: 'string'),
            new OA\Property(property: 'name_ar', type: 'string'),
            new OA\Property(property: 'is_enabled', type: 'boolean'),
        ], type: 'object')),
        new OA\Property(property: 'current_price', type: 'string', nullable: true),
        new OA\Property(property: 'price_available', type: 'boolean'),
        new OA\Property(property: 'price_is_indicative', type: 'boolean'),
        new OA\Property(property: 'you_would_receive', type: 'string', nullable: true, example: '57744.0000'),
        new OA\Property(property: 'staff_message', type: 'string', nullable: true),
        new OA\Property(property: 'staff_message_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'listed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'state_changed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'can_edit', type: 'boolean', description: 'Draft or changes requested'),
        new OA\Property(property: 'can_submit', type: 'boolean', description: 'Draft or changes requested'),
        new OA\Property(property: 'can_withdraw', type: 'boolean', description: 'Live, or (spec 011) reserved: the line is then released and refunded'),
        new OA\Property(property: 'queue_count', type: 'integer', description: 'Spec 011: buyers waiting in line'),
        new OA\Property(property: 'order', ref: '#/components/schemas/OrderSummary', nullable: true, description: 'Spec 011: the latest order on the piece (set once a buyer was accepted; state cancelled_staff when Dahab cancelled it)'),
    ],
)]
class ListingResource extends JsonResource
{
    /** States in which the latest staff note is a message to the seller. */
    private const MESSAGE_STATES = [ListingState::CHANGES_REQUESTED, ListingState::REJECTED, ListingState::WITHDRAWN];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Listing $l */
        $l = $this->resource;
        $quote = ListingPricer::for($request)->quote($l);
        $message = self::staffMessage($l);

        return self::fields($l, $quote, ListingMediaResource::CUSTOMER) + [
            'staff_message' => $message?->note,
            'staff_message_at' => $message?->changed_at->toIso8601String(),
            'created_at' => $l->created_at->toIso8601String(),
            'listed_at' => $l->listed_at?->toIso8601String(),
            'state_changed_at' => $l->state_changed_at->toIso8601String(),
            'can_edit' => $l->state->isEditable(),
            'can_submit' => $l->state->isEditable(),
            // Spec 011: withdrawing a reserved piece releases and refunds its line.
            'can_withdraw' => $l->state === ListingState::LIVE || $l->state === ListingState::RESERVED,
            'queue_count' => $l->active_queue_count,
            'order' => OrderSummaryResource::shape($l->relationLoaded('order') ? $l->order : null),
        ];
    }

    /**
     * What the seller and staff shapes share.
     *
     * @return array<string, mixed>
     */
    public static function fields(Listing $l, ListingQuote $quote, string $mediaRoute): array
    {
        return [
            'id' => $l->listing_id,
            'state' => $l->state->value,
            'category' => $l->category->value,
            'piece_type' => [
                'id' => $l->piece_type_id,
                'name_en' => $l->pieceType?->name_en,
                'name_ar' => $l->pieceType?->name_ar,
            ],
            'karat' => $l->karat_code,
            'stated_weight_g' => $l->stated_weight_g === null ? null : bcadd((string) $l->stated_weight_g, '0', 3),
            'making_charge_per_g' => $l->making_charge_per_g === null ? null : bcadd((string) $l->making_charge_per_g, '0', 4),
            'asking_price' => $l->asking_price === null ? null : bcadd((string) $l->asking_price, '0', 4),
            'description' => $l->description,
            'media' => ListingMediaResource::many($l->media, $mediaRoute),
            'branch_options' => $l->branches->map(fn (Branch $b) => [
                'id' => $b->branch_id,
                'name_en' => $b->name_en,
                'name_ar' => $b->name_ar,
                'is_enabled' => (bool) $b->is_enabled,
            ])->values()->all(),
            'current_price' => $quote->currentPrice,
            'price_available' => $quote->priceAvailable(),
            'price_is_indicative' => $quote->priceIsIndicative,
            'you_would_receive' => $quote->youWouldReceive,
        ];
    }

    /**
     * The staff note the seller should read now: the one that put the listing
     * where it is (changes requested, rejected, or taken down by staff).
     */
    public static function staffMessage(Listing $l): ?ListingStateChange
    {
        if (! in_array($l->state, self::MESSAGE_STATES, true)) {
            return null;
        }

        /** @var ListingStateChange|null $last */
        $last = $l->changes->last();

        return $last !== null && $last->actor_staff_id !== null && $last->to_state === $l->state && filled($last->note)
            ? $last
            : null;
    }
}
