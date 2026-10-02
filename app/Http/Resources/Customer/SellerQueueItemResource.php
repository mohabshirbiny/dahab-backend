<?php

namespace App\Http\Resources\Customer;

use App\Models\BuyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One request in the line on the seller's own listing (spec 011 FR-013). The
 * buyer is their display reference and nothing else: no id, name, phone or
 * email (BuyRequestLeakTest).
 */
#[OA\Schema(
    schema: 'SellerQueueItem',
    required: ['id', 'place_in_line', 'queue_position', 'is_head', 'buyer', 'locked_total_price', 'requested_at', 'seller_reply_deadline'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'place_in_line', type: 'integer', example: 1),
        new OA\Property(property: 'queue_position', type: 'integer'),
        new OA\Property(property: 'is_head', type: 'boolean', description: 'The only request the seller can accept or decline'),
        new OA\Property(property: 'buyer', properties: [new OA\Property(property: 'display_ref', type: 'string', example: '4417')], type: 'object'),
        new OA\Property(property: 'locked_total_price', type: 'string', example: '58200.0000'),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'seller_reply_deadline', type: 'string', format: 'date-time'),
    ],
)]
class SellerQueueItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BuyRequest $r */
        $r = $this->resource;
        $place = (int) $r->getAttribute('place_in_line');

        return [
            'id' => $r->buy_request_id,
            'place_in_line' => $place,
            'queue_position' => $r->queue_position,
            'is_head' => $place === 1,
            'buyer' => ['display_ref' => $r->buyer?->display_ref],
            'locked_total_price' => bcadd((string) $r->locked_total_price, '0', 4),
            'requested_at' => $r->requested_at->toIso8601String(),
            'seller_reply_deadline' => $r->seller_reply_deadline->toIso8601String(),
        ];
    }
}
