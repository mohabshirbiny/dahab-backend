<?php

namespace App\Http\Resources\Staff;

use App\Enums\ListingState;
use App\Enums\StaffPermission;
use App\Http\Resources\Customer\ListingResource as CustomerListingResource;
use App\Http\Resources\ListingMediaResource;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use App\Support\Listings\ListingPricer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardListing',
    description: 'A listing as review staff see it (spec 010): every field and every media item (the private invoice included), the seller, and what the signed-in staff member may do with it now. The detail endpoint adds `history` and the "sent back" counters.',
    required: ['id', 'state', 'category', 'piece_type', 'karat', 'stated_weight_g', 'making_charge_per_g', 'asking_price', 'description', 'media', 'branch_options', 'current_price', 'price_available', 'price_is_indicative', 'you_would_receive', 'seller', 'created_at', 'listed_at', 'state_changed_at', 'can_approve', 'can_request_changes', 'can_reject', 'can_take_down'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', example: 'in_review'),
        new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
        new OA\Property(property: 'piece_type', type: 'object'),
        new OA\Property(property: 'karat', type: 'integer', nullable: true),
        new OA\Property(property: 'stated_weight_g', type: 'string', nullable: true),
        new OA\Property(property: 'making_charge_per_g', type: 'string', nullable: true),
        new OA\Property(property: 'asking_price', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'media', type: 'array', items: new OA\Items(ref: '#/components/schemas/ListingMedia')),
        new OA\Property(property: 'branch_options', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'current_price', type: 'string', nullable: true),
        new OA\Property(property: 'price_available', type: 'boolean'),
        new OA\Property(property: 'price_is_indicative', type: 'boolean'),
        new OA\Property(property: 'you_would_receive', type: 'string', nullable: true),
        new OA\Property(property: 'gold_rate_per_gram', type: 'string', nullable: true, description: 'What buyers pay per gram of this karat now; with the making charge it gives the design\'s "as % of gold value"'),
        new OA\Property(property: 'seller', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'display_ref', type: 'string', example: '008842'),
            new OA\Property(property: 'full_name', type: 'string', nullable: true),
            new OA\Property(property: 'phone_masked', type: 'string', example: '+20 10 •••• 8842'),
            new OA\Property(property: 'status', type: 'string', example: 'active'),
        ], type: 'object'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'listed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'state_changed_at', type: 'string', format: 'date-time', description: 'For a waiting listing: since when it has waited'),
        new OA\Property(property: 'can_approve', type: 'boolean'),
        new OA\Property(property: 'can_request_changes', type: 'boolean'),
        new OA\Property(property: 'can_reject', type: 'boolean'),
        new OA\Property(property: 'can_take_down', type: 'boolean'),
        new OA\Property(property: 'history', type: 'array', description: 'Detail only. Oldest first.', items: new OA\Items(properties: [
            new OA\Property(property: 'from_state', type: 'string', nullable: true),
            new OA\Property(property: 'to_state', type: 'string'),
            new OA\Property(property: 'actor', properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['customer', 'staff']),
                new OA\Property(property: 'name', type: 'string', nullable: true),
            ], type: 'object'),
            new OA\Property(property: 'note', type: 'string', nullable: true),
            new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
        ], type: 'object')),
        new OA\Property(property: 'sent_back_count', type: 'integer', description: 'Detail only. Times this piece was sent back for changes'),
        new OA\Property(property: 'seller_listings_sent_back', type: 'integer', description: 'Detail only. How many of the seller\'s listings were ever sent back'),
        new OA\Property(property: 'seller_listings_submitted', type: 'integer', description: 'Detail only. How many of the seller\'s listings were ever sent for review'),
        new OA\Property(property: 'seller_listing_number', type: 'integer', description: 'Detail only. Which of the seller\'s listings this is, in creation order'),
    ],
)]
class ListingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Listing $l */
        $l = $this->resource;
        $pricer = ListingPricer::for($request);
        $seller = $l->seller;
        /** @var Staff|null $staff */
        $staff = $request->user('staff');
        $can = fn (StaffPermission $p): bool => $staff !== null && $staff->can($p->value);

        return CustomerListingResource::fields($l, $pricer->quote($l), ListingMediaResource::DASHBOARD) + [
            'gold_rate_per_gram' => $l->karat_code === null ? null : $pricer->karatPrices($l->karat_code)?->buyersPay,
            'seller' => [
                'id' => $seller->customer_id,
                'display_ref' => $seller->display_ref,
                'full_name' => $seller->full_name,
                'phone_masked' => self::maskPhone((string) $seller->phone),
                'status' => $seller->status instanceof \BackedEnum ? $seller->status->value : (string) $seller->status,
            ],
            'created_at' => $l->created_at->toIso8601String(),
            'listed_at' => $l->listed_at?->toIso8601String(),
            'state_changed_at' => $l->state_changed_at->toIso8601String(),
            'can_approve' => $l->state === ListingState::IN_REVIEW && $can(StaffPermission::LISTING_REVIEW),
            'can_request_changes' => $l->state === ListingState::IN_REVIEW && $can(StaffPermission::LISTING_REQUEST_CHANGES),
            'can_reject' => $l->state === ListingState::IN_REVIEW && $can(StaffPermission::LISTING_REVIEW),
            'can_take_down' => $l->state === ListingState::LIVE && $can(StaffPermission::LISTING_TAKEDOWN),
        ];
    }

    /**
     * The history rows of the detail view.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Listing $l): array
    {
        return $l->changes->map(fn (ListingStateChange $c) => [
            'from_state' => $c->from_state?->value,
            'to_state' => $c->to_state->value,
            'actor' => $c->actor_staff_id !== null
                ? ['type' => 'staff', 'name' => $c->staff?->full_name]
                : ['type' => 'customer', 'name' => $l->seller?->full_name],
            'note' => $c->note,
            'changed_at' => $c->changed_at->toIso8601String(),
        ])->values()->all();
    }

    /** `+201012348842` → `+20 10 •••• 8842`: enough to recognise, not enough to call. */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 8) {
            return '••••';
        }

        return '+'.substr($digits, 0, 2).' '.substr($digits, 2, 2).' •••• '.substr($digits, -4);
    }
}
