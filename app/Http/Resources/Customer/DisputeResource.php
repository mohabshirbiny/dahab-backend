<?php

namespace App\Http\Resources\Customer;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The caller's own dispute (spec 014 FR-007, research R10). Row-level security
 * returns a dispute only to the customer who raised it; staff notes, the
 * assignee and the photos themselves are never in it.
 */
#[OA\Schema(
    schema: 'CustomerDispute',
    required: ['ref', 'order_ref', 'raised_as', 'reason', 'detail', 'photo_count', 'state', 'opened_at'],
    properties: [
        new OA\Property(property: 'ref', type: 'string', example: 'DSP-4417'),
        new OA\Property(property: 'order_ref', type: 'string'),
        new OA\Property(property: 'raised_as', type: 'string', enum: ['buyer', 'seller']),
        new OA\Property(property: 'reason', type: 'string', enum: ['not_as_listed', 'disagree_inspection', 'money_wrong', 'other_side_unresponsive', 'not_theirs_to_sell', 'other']),
        new OA\Property(property: 'detail', type: 'string'),
        new OA\Property(property: 'photo_count', type: 'integer'),
        new OA\Property(property: 'state', type: 'string', enum: ['open', 'being_looked_at', 'resolved']),
        new OA\Property(property: 'outcome', type: 'string', enum: ['resume', 'against_sale'], nullable: true),
        new OA\Property(property: 'reply', type: 'string', nullable: true, description: 'Dahab\'s reply, once resolved'),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class DisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Dispute $d */
        $d = $this->resource;

        return [
            'ref' => $d->dispute_ref,
            'order_ref' => $d->relationLoaded('order') ? $d->order?->order_ref : null,
            'raised_as' => $d->raised_as,
            'reason' => $d->reason->value,
            'detail' => $d->detail,
            'photo_count' => $d->relationLoaded('photos') ? $d->photos->count() : $d->photos()->count(),
            'state' => $d->state->customerState(),
            'outcome' => $d->outcome?->value,
            'reply' => $d->resolution_reply,
            'opened_at' => $d->opened_at->toIso8601String(),
            'resolved_at' => $d->resolved_at?->toIso8601String(),
        ];
    }
}
