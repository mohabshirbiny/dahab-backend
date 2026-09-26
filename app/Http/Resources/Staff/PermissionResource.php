<?php

namespace App\Http\Resources\Staff;

use App\Enums\StaffPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardPermission',
    description: 'One entry of the code-defined permission catalogue (read-only).',
    required: ['code', 'label', 'group', 'branch_scoped'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: 'identity.review'),
        new OA\Property(property: 'label', type: 'string', example: 'Approve or reject identity documents'),
        new OA\Property(property: 'group', type: 'string', example: 'Identity'),
        new OA\Property(property: 'branch_scoped', type: 'boolean'),
    ],
)]
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StaffPermission $p */
        $p = $this->resource;

        return [
            'code' => $p->value,
            'label' => $p->label(),
            'group' => $p->group(),
            'branch_scoped' => $p->isBranchScoped(),
        ];
    }
}
