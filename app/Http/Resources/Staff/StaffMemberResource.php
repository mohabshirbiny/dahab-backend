<?php

namespace App\Http\Resources\Staff;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStaffMember',
    description: 'A staff member as seen by role managers (spec 002). The system actor never appears.',
    required: ['id', 'full_name', 'email', 'is_active', 'is_founder', 'roles', 'permissions'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_founder', type: 'boolean', description: 'Read-only; never changeable through the API.'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardRoleRef')),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: 'Effective permissions (union of roles), sorted'),
        new OA\Property(property: 'mfa_enrolled', type: 'boolean'),
    ],
)]
class StaffMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Staff $s */
        $s = $this->resource;
        $roles = $s->roles->sortBy('name')->values();

        return [
            'id' => $s->staff_id,
            'full_name' => $s->full_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'is_active' => (bool) $s->is_active,
            'is_founder' => (bool) $s->is_founder,
            'branch_id' => $s->branch_id,
            'roles' => $roles->map(fn ($r) => ['name' => $r->name, 'display_name' => $r->display_name])->all(),
            // From the eager-loaded roles.permissions: no query per row.
            'permissions' => $roles->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique()->sort()->values()->all(),
            'mfa_enrolled' => $s->relationLoaded('mfa') ? $s->mfa !== null : $s->mfa()->exists(),
        ];
    }
}
