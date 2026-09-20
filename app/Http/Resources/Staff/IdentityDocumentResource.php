<?php

namespace App\Http\Resources\Staff;

use App\Models\IdentityDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffIdentityDocument',
    description: 'Review metadata of an identity document. Never includes storage refs.',
    properties: [
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'verified', 'needs_resubmission', 'rejected']),
        new OA\Property(property: 'has_back', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reviewed_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reviewed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'review_reasons', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'review_note', type: 'string', nullable: true),
        new OA\Property(property: 'image_available', type: 'boolean'),
        new OA\Property(property: 'customer', type: 'object', nullable: true),
    ],
)]
class IdentityDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var IdentityDocument $d */
        $d = $this->resource;

        return [
            'document_id' => $d->document_id,
            'doc_kind' => $d->doc_kind->value,
            'status' => $d->status->value,
            'has_back' => $d->back_ref !== null,
            'created_at' => $d->created_at->toIso8601String(),
            'reviewed_by' => $d->reviewed_by,
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            'review_reasons' => $d->review_reasons,
            'review_note' => $d->review_note,
            'image_available' => $d->image_deleted_at === null,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $d->customer->customer_id,
                'display_ref' => $d->customer->display_ref,
                'status' => $d->customer->status->value,
            ]),
        ];
    }
}
