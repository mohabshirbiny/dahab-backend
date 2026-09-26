<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\ListRolesAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Authorization\DeleteRoleRequest;
use App\Http\Requests\Dashboard\Authorization\StoreRoleRequest;
use App\Http\Requests\Dashboard\Authorization\UpdateRoleRequest;
use App\Http\Resources\Staff\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Dashboard-managed roles (spec 002 User Story 1). Every operation needs
 * roles.manage. Mutations refuse self-escalation (403 escalation_denied),
 * need a reason where FR-055 says so (422 reason_required), and are audited.
 */
#[OA\Tag(name: 'Dashboard Access Control', description: 'Dashboard API — roles, their permissions, and staff role assignment (spec 002). Roles and permissions: roles.manage; staff list: staff.view.')]
class RoleController extends Controller
{
    public function __construct(private readonly ListRolesAction $roles) {}

    #[OA\Get(
        path: '/dashboard/roles',
        operationId: 'dashboardListRoles',
        summary: 'List roles with their permissions and holder counts',
        description: 'Ordered by display_name; at most 200. Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        responses: [
            new OA\Response(response: 200, description: 'Roles', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardRole')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection($this->roles->handle());
    }

    #[OA\Post(
        path: '/dashboard/roles',
        operationId: 'dashboardCreateRole',
        summary: 'Create a role',
        description: 'Every permission must be one the actor holds. Audited (authz.role.created). Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStoreRole')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardRole'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreRoleRequest $request, CreateRoleAction $create): JsonResponse
    {
        $role = $create->handle($request->user('staff'), $request->validated());

        return RoleResource::make($this->roles->find($role->name))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/dashboard/roles/{role}',
        operationId: 'dashboardShowRole',
        summary: 'Show one role',
        description: 'Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role machine name', schema: new OA\Schema(type: 'string', pattern: '^[a-z][a-z0-9_]{2,49}$'))],
        responses: [
            new OA\Response(response: 200, description: 'Role', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardRole'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(string $role): RoleResource
    {
        return RoleResource::make($this->roles->find($role));
    }

    #[OA\Patch(
        path: '/dashboard/roles/{role}',
        operationId: 'dashboardUpdateRole',
        summary: 'Edit a role (display name, description, MFA flag, permission set)',
        description: 'The actor must not hold this role. `permissions` replaces the whole set; every added or removed code must be held by the actor. `reason` is required when the permissions or requires_mfa change. Takes effect on each holder\'s next request. Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role machine name', schema: new OA\Schema(type: 'string', pattern: '^[a-z][a-z0-9_]{2,49}$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateRole')),
        responses: [
            new OA\Response(response: 200, description: 'Updated role', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardRole'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'last_role_manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateRoleRequest $request, string $role, UpdateRoleAction $update): RoleResource
    {
        $update->handle($request->user('staff'), $this->roles->find($role), $request->validated());

        return RoleResource::make($this->roles->find($role));
    }

    #[OA\Delete(
        path: '/dashboard/roles/{role}',
        operationId: 'dashboardDeleteRole',
        summary: 'Delete a role no staff member holds',
        description: 'The actor must not hold the role. A reason is required. Audited (authz.role.deleted). Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role machine name', schema: new OA\Schema(type: 'string', pattern: '^[a-z][a-z0-9_]{2,49}$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardDeleteRole')),
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'role_in_use | last_role_manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function destroy(DeleteRoleRequest $request, string $role, DeleteRoleAction $delete): Response
    {
        $delete->handle($request->user('staff'), $this->roles->find($role), $request->validated('reason'));

        return response()->noContent();
    }
}
