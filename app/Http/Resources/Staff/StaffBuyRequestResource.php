<?php

namespace App\Http\Resources\Staff;

use App\Enums\BuyRequestState;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\BuyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A buy request on the staff Buy requests page (spec 012 US10, FR-023).
 * Buyer and seller by display reference (and id, to open the Customer file
 * for holders of customer.view) — never a name, phone or email.
 */
#[OA\Schema(
    schema: 'StaffBuyRequest',
    description: 'A buy request for staff (spec 012). Money as 4-dp strings. place_in_line / ahead_count only while queued.',
    required: ['id', 'state', 'listing', 'seller', 'buyer', 'place_in_line', 'queue_length', 'locked_total_price', 'deposit_amount', 'requested_at', 'seller_reply_deadline', 'near_expiry', 'order_ref'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', enum: ['queued', 'accepted', 'released_not_chosen', 'released_declined', 'released_expired', 'withdrawn_by_buyer']),
        new OA\Property(property: 'listing', type: 'object', description: 'The piece: listing_id, category, piece_type, karat, weight_g, listing_state, photo'),
        new OA\Property(property: 'seller', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'buyer', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'place_in_line', type: 'integer', nullable: true),
        new OA\Property(property: 'queue_length', type: 'integer'),
        new OA\Property(property: 'locked_total_price', type: 'string'),
        new OA\Property(property: 'deposit_amount', type: 'string'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'seller_reply_deadline', type: 'string', format: 'date-time'),
        new OA\Property(property: 'near_expiry', type: 'boolean', description: 'Queued and the reply deadline is within the near-expiry window (6 h)'),
        new OA\Property(property: 'order_ref', type: 'string', nullable: true, description: 'Once accepted'),
    ],
)]
class StaffBuyRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BuyRequest $r */
        $r = $this->resource;
        $l = $r->listing;
        $ahead = $r->state === BuyRequestState::QUEUED ? $r->getAttribute('ahead_count') : null;

        return [
            'id' => $r->buy_request_id,
            'state' => $r->state->value,
            'listing' => CustomerOrderResource::piece($l),
            'seller' => ['customer_id' => $l->seller_id, 'display_ref' => $l->seller?->display_ref],
            'buyer' => ['customer_id' => $r->buyer_id, 'display_ref' => $r->buyer?->display_ref],
            'place_in_line' => $ahead === null ? null : (int) $ahead + 1,
            'queue_length' => (int) $l->active_queue_count,
            'locked_total_price' => bcadd((string) $r->locked_total_price, '0', 4),
            'deposit_amount' => bcadd((string) $r->deposit_amount, '0', 4),
            'requested_at' => $r->requested_at->toIso8601String(),
            'seller_reply_deadline' => $r->seller_reply_deadline->toIso8601String(),
            'near_expiry' => (bool) $r->getAttribute('near_expiry'),
            'order_ref' => $r->relationLoaded('order') ? $r->order?->order_ref : null,
        ];
    }
}
