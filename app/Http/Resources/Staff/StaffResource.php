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
        new OA\Property(property: 'role', type: 'string', nullable: true, deprecated: true, description: 'Deprecated (spec 002): roles are dynamic. First role name alphabetically, or null. Use roles / roles_detail.'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_founder', type: 'boolean', description: 'Read-only; never changeable through the API.'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Role machine names, sorted'),
        new OA\Property(property: 'roles_detail', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardRoleRef')),
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
        $roles = $s->roles->sortBy('name')->values();

        return [
            'id' => $s->staff_id,
            'role' => $roles->first()?->name,
            'full_name' => $s->full_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'is_active' => (bool) $s->is_active,
            'is_founder' => (bool) $s->is_founder,
            'branch_id' => $s->branch_id,
            // Effective Spatie roles/permissions (via roles), guard "staff".
            'roles' => $roles->pluck('name')->all(),
            'roles_detail' => $roles->map(fn ($r) => ['name' => $r->name, 'display_name' => $r->display_name])->all(),
            'permissions' => $s->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'mfa_enrolled' => $s->mfa()->exists(),
            'created_at' => optional($s->created_at)->toIso8601String(),
        ];
    }
}
