<?php

namespace App\Http\Resources\Staff;

use App\Models\ListingReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A listing report for staff (spec 017 FR-053). Staff see the reporter's
 * reference; the seller never does (no customer resource carries it).
 *
 * @mixin ListingReport
 */
#[OA\Schema(
    schema: 'StaffListingReport',
    required: ['id', 'reference', 'reason', 'reason_label', 'note', 'state', 'listing', 'reporter_ref', 'created_at', 'handled_at', 'handled_by', 'staff_note'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'reference', type: 'string', example: 'RPT-2291'),
        new OA\Property(property: 'reason', type: 'string', enum: ['photos_not_genuine', 'price_or_weight_wrong', 'description_mismatch', 'not_theirs_to_sell', 'off_platform_dealing', 'other']),
        new OA\Property(property: 'reason_label', type: 'string'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'state', type: 'string', enum: ['open', 'dismissed', 'actioned', 'listing_gone']),
        new OA\Property(property: 'listing', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'title', type: 'string', example: 'Gold ring, 21K'),
            new OA\Property(property: 'state', type: 'string'),
            new OA\Property(property: 'seller_ref', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'reporter_ref', type: 'string', description: 'The reporter display reference — staff only'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'handled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'handled_by', type: 'string', nullable: true, description: 'Staff name'),
        new OA\Property(property: 'staff_note', type: 'string', nullable: true),
    ],
)]
class ListingReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $listing = $this->listing;

        return [
            'id' => $this->report_id,
            'reference' => $this->reference(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'note' => $this->note,
            'state' => $this->state->value,
            'listing' => [
                'id' => $this->listing_id,
                'title' => $listing?->title(),
                'state' => $listing?->state->value,
                'seller_ref' => $listing?->seller?->display_ref,
            ],
            'reporter_ref' => $this->reporter?->display_ref,
            'created_at' => $this->created_at->toIso8601String(),
            'handled_at' => $this->handled_at?->toIso8601String(),
            'handled_by' => $this->handler?->full_name,
            'staff_note' => $this->staff_note,
        ];
    }
}
