<?php

namespace App\Http\Resources\Staff;

use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffCustomerFile',
    description: 'One customer\'s file (spec 007): the StaffCustomerVerification fields plus every identity document and the suspension details. The suspension note is staff-only and never appears in customer responses.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/StaffCustomerVerification'),
        new OA\Schema(properties: [
            new OA\Property(property: 'preferred_lang', type: 'string', enum: ['ar', 'en']),
            new OA\Property(property: 'joined_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'documents', type: 'array', description: 'Every identity document, newest first; documents[0] equals latest_document.', items: new OA\Items(ref: '#/components/schemas/StaffCustomerFileDocument')),
            new OA\Property(property: 'suspension', ref: '#/components/schemas/StaffCustomerSuspension', nullable: true),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'StaffCustomerFileDocument',
    properties: [
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'verified', 'needs_resubmission', 'rejected']),
        new OA\Property(property: 'has_back', type: 'boolean'),
        new OA\Property(property: 'review_reasons', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'review_note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reviewed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reviewed_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'StaffCustomerSuspension',
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['piece_misrepresented', 'off_platform_dealing', 'repeated_disputes', 'reported_by_users', 'identity_unconfirmed', 'customer_request', 'other']),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'status_before', type: 'string', enum: ['pending_verification', 'active', 'rejected']),
        new OA\Property(property: 'suspended_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'suspended_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
    ],
)]
class CustomerFileResource extends CustomerVerificationResource
{
    public function toArray(Request $request): array
    {
        /** @var Customer $c */
        $c = $this->resource;

        $documents = $c->identityDocuments->map(fn (IdentityDocument $d) => [
            ...$this->document($d),
            'reviewed_by' => $this->staffRef($d->reviewer),
        ])->values()->all();

        return [
            ...parent::toArray($request),
            'latest_document' => $documents[0] ?? null,
            'preferred_lang' => $c->preferred_lang,
            'joined_at' => optional($c->created_at)->toIso8601String(),
            'documents' => $documents,
            'suspension' => $c->status_before_suspension === null ? null : [
                'reason' => $c->suspended_reason?->value,
                'note' => $c->suspended_note,
                'status_before' => $c->status_before_suspension->value,
                'suspended_at' => optional($c->suspended_at)->toIso8601String(),
                'suspended_by' => $this->staffRef($c->suspender),
            ],
        ];
    }

    /** @return array{id: string, full_name: ?string}|null the DashboardStaffRef shape */
    private function staffRef(?Staff $staff): ?array
    {
        return $staff === null ? null : ['id' => $staff->staff_id, 'full_name' => $staff->full_name];
    }
}
