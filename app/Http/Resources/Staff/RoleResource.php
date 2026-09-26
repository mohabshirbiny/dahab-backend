<?php

namespace App\Http\Resources\Staff;

use App\Models\StaffRoleModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardRoleRef',
    description: 'A role as shown next to a staff member.',
    required: ['name', 'display_name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'verification'),
        new OA\Property(property: 'display_name', type: 'string', example: 'Verification'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardRole',
    description: 'A Dashboard-managed staff role (spec 002).',
    required: ['name', 'display_name', 'requires_mfa', 'permissions', 'staff_count', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'name', type: 'string', pattern: '^[a-z][a-z0-9_]{2,49}$', description: 'Machine name, immutable', example: 'customer_support'),
        new OA\Property(property: 'display_name', type: 'string', maxLength: 100, example: 'Customer support'),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'requires_mfa', type: 'boolean'),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: 'Permission codes, sorted'),
        new OA\Property(property: 'staff_count', type: 'integer', description: 'Staff holding the role (system actor excluded)'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StaffRoleModel $r */
        $r = $this->resource;

        return [
            'name' => $r->name,
            'display_name' => $r->display_name,
            'description' => $r->description,
            'requires_mfa' => (bool) $r->requires_mfa,
            'permissions' => $r->permissions->pluck('name')->sort()->values()->all(),
            'staff_count' => (int) ($r->staff_count ?? $r->holderCount()),
            'created_at' => optional($r->created_at)->toIso8601String(),
            'updated_at' => optional($r->updated_at)->toIso8601String(),
        ];
    }
}
