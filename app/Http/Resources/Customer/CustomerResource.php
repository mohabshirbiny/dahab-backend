<?php

namespace App\Http\Resources\Customer;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Governorate;
use App\Enums\IdentityDocumentStatus;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Support\Legal\LegalAcceptanceRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerProfile',
    description: 'The authenticated customer. Never includes a password field.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'display_ref', type: 'string', example: '000123'),
        new OA\Property(property: 'phone', type: 'string', example: '+201000000001'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'full_name', type: 'string', nullable: true),
        new OA\Property(property: 'preferred_lang', type: 'string', enum: ['ar', 'en']),
        new OA\Property(property: 'governorate', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['pending_verification', 'active', 'rejected', 'suspended', 'closed']),
        new OA\Property(property: 'is_verified', type: 'boolean', description: 'Legacy flag; derived from status'),
        new OA\Property(property: 'is_suspended', type: 'boolean', description: 'Legacy flag; derived from status'),
        new OA\Property(property: 'suspended_reason', type: 'string', nullable: true),
        new OA\Property(property: 'suspended_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'trade_allowed', type: 'boolean', description: 'True only when status = active'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'customer_type', type: 'string', enum: ['ordinary', 'market_maker'], description: 'A market-maker account sees approved pieces marked (spec 019 6B)'),
        new OA\Property(property: 'verification', ref: '#/components/schemas/CustomerVerificationState', description: 'Testing fixes, item 4: where the identity check of the customer stands, from their latest document'),
        new OA\Property(property: 'legal_acceptance_required', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegalAcceptanceRequired'), description: 'Terms or privacy owed before any state-changing request (spec 019); empty when nothing is owed'),
    ],
)]
#[OA\Schema(
    schema: 'CustomerVerificationState',
    description: 'The latest identity document of the customer, as the app needs it. document_status is null when no document was ever sent. Never includes the images or storage refs.',
    properties: [
        new OA\Property(property: 'document_status', type: 'string', enum: ['pending', 'needs_resubmission', 'verified', 'rejected'], nullable: true),
        new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport'], nullable: true),
        new OA\Property(property: 'reasons', type: 'array', items: new OA\Items(type: 'string', enum: ['blurred_or_glare', 'card_cut_off', 'name_does_not_match', 'card_expired', 'back_missing', 'text_not_readable']), description: 'The reasons of the reviewer for "upload again" or a rejection; empty otherwise'),
        new OA\Property(property: 'note', type: 'string', nullable: true, description: 'The note of the reviewer to the customer'),
        new OA\Property(property: 'attempt_number', type: 'integer', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reviewed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'can_submit', type: 'boolean', description: 'True when POST /customer/me/identity-documents would take a new attempt: no document yet, or the latest was sent back or rejected (and the account is not closed)'),
    ],
    type: 'object',
)]
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Customer $c */
        $c = $this->resource;

        return [
            'id' => $c->customer_id,
            'display_ref' => $c->display_ref,
            'phone' => $c->phone,
            'email' => $c->email,
            'email_verified_at' => optional($c->email_verified_at)->toIso8601String(),
            'full_name' => $c->full_name,
            'preferred_lang' => $c->preferred_lang,
            'governorate' => $c->governorate instanceof Governorate ? $c->governorate->value : $c->governorate,
            'status' => $c->status instanceof CustomerStatus ? $c->status->value : $c->status,
            'is_verified' => (bool) $c->is_verified,
            'is_suspended' => (bool) $c->is_suspended,
            'suspended_reason' => $c->suspended_reason instanceof SuspendedReason ? $c->suspended_reason->value : $c->suspended_reason,
            'suspended_at' => optional($c->suspended_at)->toIso8601String(),
            'trade_allowed' => $c->tradeAllowed(),
            'created_at' => optional($c->created_at)->toIso8601String(),
            'legal_acceptance_required' => LegalAcceptanceRequirement::for($c->customer_id),
            'customer_type' => ($c->customer_type ?? CustomerType::ORDINARY)->value,
            'verification' => self::verification($c),
        ];
    }

    /** @return array<string, mixed> */
    public static function verification(Customer $c): array
    {
        /** @var IdentityDocument|null $d */
        $d = IdentityDocument::query()->where('customer_id', $c->customer_id)
            ->orderByDesc('attempt_number')->first();

        $status = $d?->status;
        $closed = ($c->status instanceof CustomerStatus ? $c->status : CustomerStatus::tryFrom((string) $c->status)) === CustomerStatus::CLOSED;

        return [
            'document_status' => $status?->value,
            'doc_kind' => $d?->doc_kind->value,
            'reasons' => $d?->review_reasons ?? [],
            'note' => $d?->review_note,
            'attempt_number' => $d?->attempt_number,
            'submitted_at' => $d?->created_at->toIso8601String(),
            'reviewed_at' => $d?->reviewed_at?->toIso8601String(),
            'can_submit' => ! $closed && ($status === null
                || in_array($status, [IdentityDocumentStatus::NEEDS_RESUBMISSION, IdentityDocumentStatus::REJECTED], true)),
        ];
    }
}
