<?php

namespace App\Http\Resources\Staff;

use App\Enums\Governorate;
use App\Models\Customer;
use App\Models\IdentityDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffCustomerVerification',
    description: 'Customer row for the Dashboard\'s Users and Verification page. Never includes storage refs.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'display_ref', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string'),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'governorate', type: 'string', nullable: true),
        new OA\Property(property: 'customer_type', type: 'string', enum: ['ordinary', 'market_maker']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending_verification', 'active', 'rejected', 'suspended']),
        new OA\Property(property: 'suspended_reason', type: 'string', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'latest_document', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'verified', 'needs_resubmission', 'rejected']),
            new OA\Property(property: 'has_back', type: 'boolean'),
            new OA\Property(property: 'review_reasons', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            new OA\Property(property: 'review_note', type: 'string', nullable: true),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'reviewed_at', type: 'string', format: 'date-time', nullable: true),
        ]),
    ],
)]
class CustomerVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Customer $c */
        $c = $this->resource;

        $latest = $c->identityDocuments->first();

        return [
            'id' => $c->customer_id,
            'display_ref' => $c->display_ref,
            'full_name' => $c->full_name,
            'phone' => $c->phone,
            'email' => $c->email,
            'governorate' => $c->governorate instanceof Governorate ? $c->governorate->value : $c->governorate,
            'customer_type' => $c->customer_type->value,
            'status' => $c->status->value,
            'suspended_reason' => $c->suspended_reason?->value,
            'submitted_at' => optional($c->created_at)->toIso8601String(),
            'latest_document' => $latest instanceof IdentityDocument ? [
                'document_id' => $latest->document_id,
                'doc_kind' => $latest->doc_kind->value,
                'status' => $latest->status->value,
                'has_back' => $latest->back_ref !== null,
                'review_reasons' => $latest->review_reasons,
                'review_note' => $latest->review_note,
                'created_at' => $latest->created_at->toIso8601String(),
                'reviewed_at' => $latest->reviewed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
