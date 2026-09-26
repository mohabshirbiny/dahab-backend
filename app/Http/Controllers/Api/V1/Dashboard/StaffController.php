<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Authorization\ListStaffAction;
use App\Actions\Authorization\SetStaffRolesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Authorization\ListStaffRequest;
use App\Http\Requests\Dashboard\Authorization\SetStaffRolesRequest;
use App\Http\Resources\Staff\StaffMemberResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Staff members and their roles (spec 002 User Story 2). Listing needs
 * staff.view; changing roles needs roles.manage. The system actor is never
 * listed and cannot be targeted (404).
 */
class StaffController extends Controller
{
    public function __construct(private readonly ListStaffAction $staff) {}

    #[OA\Get(
        path: '/dashboard/staff',
        operationId: 'dashboardListStaff',
        summary: 'List staff members (system actor excluded)',
        description: 'Ordered by full_name. Requires staff.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', required: false, description: 'Only staff holding this role', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated staff', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardStaffMember')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListStaffRequest $request): AnonymousResourceCollection
    {
        return StaffMemberResource::collection($this->staff->handle($request->role(), $request->perPage()));
    }

    #[OA\Get(
        path: '/dashboard/staff/{staff}',
        operationId: 'dashboardShowStaff',
        summary: 'Show one staff member',
        description: 'Requires staff.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Staff member', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(string $staff): StaffMemberResource
    {
        return StaffMemberResource::make($this->staff->find($staff));
    }

    #[OA\Put(
        path: '/dashboard/staff/{staff}/roles',
        operationId: 'dashboardSetStaffRoles',
        summary: 'Replace the roles a staff member holds',
        description: 'Not allowed on yourself. Every permission of every role added or removed must be held by the actor. A reason is required. Founder status is unaffected. Audited (authz.staff.roles_changed). Takes effect on the target\'s next request. Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSetStaffRoles')),
        responses: [
            new OA\Response(response: 200, description: 'Updated staff member', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'last_role_manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function updateRoles(SetStaffRolesRequest $request, string $staff, SetStaffRolesAction $set): StaffMemberResource
    {
        $set->handle($request->user('staff'), $this->staff->find($staff), $request->validated('roles'), $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($staff));
    }
}
