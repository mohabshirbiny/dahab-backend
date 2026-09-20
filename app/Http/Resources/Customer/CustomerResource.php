<?php

namespace App\Http\Resources\Customer;

use App\Enums\CustomerStatus;
use App\Enums\Governorate;
use App\Enums\SuspendedReason;
use App\Models\Customer;
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
        new OA\Property(property: 'status', type: 'string', enum: ['pending_verification', 'active', 'rejected', 'suspended']),
        new OA\Property(property: 'is_verified', type: 'boolean', description: 'Legacy flag; derived from status'),
        new OA\Property(property: 'is_suspended', type: 'boolean', description: 'Legacy flag; derived from status'),
        new OA\Property(property: 'suspended_reason', type: 'string', nullable: true),
        new OA\Property(property: 'suspended_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'trade_allowed', type: 'boolean', description: 'True only when status = active'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
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
        ];
    }
}
