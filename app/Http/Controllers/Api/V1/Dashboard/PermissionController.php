<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\PermissionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/** The code-defined permission catalogue, for the role editor (spec 002 FR-002). */
class PermissionController extends Controller
{
    #[OA\Get(
        path: '/dashboard/permissions',
        operationId: 'dashboardListPermissions',
        summary: 'The permission catalogue (code-defined, read-only)',
        description: 'Every permission a role can hold, ordered by group then code. Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        responses: [
            new OA\Response(response: 200, description: 'Permission catalogue', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardPermission')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $catalogue = collect(StaffPermission::cases())
            ->sortBy(fn (StaffPermission $p) => [$p->group(), $p->value])
            ->values();

        return PermissionResource::collection($catalogue);
    }
}
