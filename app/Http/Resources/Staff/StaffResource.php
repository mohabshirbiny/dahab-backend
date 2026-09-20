<?php

namespace App\Http\Resources\Staff;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffProfile',
    description: 'The authenticated staff member with their Spatie roles and effective permissions. Never includes a password field.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'role', type: 'string', enum: ['ceo', 'coo', 'finance', 'operations', 'verification', 'igi_branch']),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: 'Effective permissions (direct + via roles), e.g. customer.suspend'),
        new OA\Property(property: 'mfa_enrolled', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Staff $s */
        $s = $this->resource;

        return [
            'id' => $s->staff_id,
            'role' => $s->role->value,
            'full_name' => $s->full_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'is_active' => (bool) $s->is_active,
            'branch_id' => $s->branch_id,
            // Effective Spatie roles/permissions (direct + via roles), guard "staff".
            'roles' => $s->getRoleNames()->sort()->values()->all(),
            'permissions' => $s->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'mfa_enrolled' => $s->mfa()->exists(),
            'created_at' => optional($s->created_at)->toIso8601String(),
        ];
    }
}
