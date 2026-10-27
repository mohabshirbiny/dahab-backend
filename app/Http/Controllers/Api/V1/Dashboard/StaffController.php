<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Authorization\CreateStaffAction;
use App\Actions\Authorization\DeleteStaffAction;
use App\Actions\Authorization\DisableStaffAction;
use App\Actions\Authorization\EnableStaffAction;
use App\Actions\Authorization\ListStaffAction;
use App\Actions\Authorization\SetStaffBranchAction;
use App\Actions\Authorization\SetStaffRolesAction;
use App\Actions\Authorization\UpdateStaffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Authorization\CreateStaffRequest;
use App\Http\Requests\Dashboard\Authorization\ListStaffRequest;
use App\Http\Requests\Dashboard\Authorization\SetStaffBranchRequest;
use App\Http\Requests\Dashboard\Authorization\SetStaffRolesRequest;
use App\Http\Requests\Dashboard\Authorization\StaffStatusChangeRequest;
use App\Http\Requests\Dashboard\Authorization\UpdateStaffRequest;
use App\Http\Resources\Staff\StaffMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'active or disabled; omitted = all', schema: new OA\Schema(type: 'string', enum: ['active', 'disabled'])),
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
        return StaffMemberResource::collection($this->staff->handle($request->role(), $request->perPage(), $request->status()));
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

    #[OA\Post(
        path: '/dashboard/staff',
        operationId: 'dashboardCreateStaff',
        summary: 'Create a staff member without a password',
        description: 'The account is active with no password: an invitation link (48 hours) is mailed after commit and the member chooses one. Roles are assigned in the same step, bounded by the permissions the actor holds. A reason is required. Founder status cannot be set. Audited (authz.staff.created). Requires staff.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardCreateStaff')),
        responses: [
            new OA\Response(response: 201, description: 'Created staff member (password_set false)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (including an email already used) | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(CreateStaffRequest $request, CreateStaffAction $create): JsonResponse
    {
        $created = $create->handle($request->user('staff'), $request->validated(), $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($created->staff_id))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/dashboard/staff/{staff}/disable',
        operationId: 'dashboardDisableStaff',
        summary: 'Disable a staff member',
        description: 'Not yourself, never a founder, only an active member. Every token is revoked at once and open set-password links are voided; the member keeps their name in history and appears under status=disabled. A reason is required. Audited (authz.staff.disabled). Requires staff.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStaffStatusChange')),
        responses: [
            new OA\Response(response: 200, description: 'The disabled staff member', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied (yourself) | founder_protected | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'staff_already_disabled | last_role_manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'reason_required | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function disable(StaffStatusChangeRequest $request, string $staff, DisableStaffAction $disable): StaffMemberResource
    {
        $disable->handle($request->user('staff'), $staff, $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($staff));
    }

    #[OA\Post(
        path: '/dashboard/staff/{staff}/enable',
        operationId: 'dashboardEnableStaff',
        summary: 'Enable a disabled staff member',
        description: 'The old password is deleted and a 48-hour set-password link is mailed: the link is the only way back in. The authenticator enrolment is kept. A reason is required. Audited (authz.staff.enabled). Requires staff.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStaffStatusChange')),
        responses: [
            new OA\Response(response: 200, description: 'The enabled staff member (password_set false)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied (yourself) | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'staff_not_disabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'reason_required | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function enable(StaffStatusChangeRequest $request, string $staff, EnableStaffAction $enable): StaffMemberResource
    {
        $enable->handle($request->user('staff'), $staff, $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($staff));
    }

    #[OA\Patch(
        path: '/dashboard/staff/{staff}',
        operationId: 'dashboardUpdateStaff',
        summary: "Change a staff member's name, email or phone",
        description: 'Only the fields sent change; a phone cannot be emptied. A founder\'s details are changed only by a founder (403 founder_protected). A new email voids open set-password links, and a member with no password yet gets a fresh invitation there. A reason is required. Audited (authz.staff.updated, before and after). Requires staff.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateStaff')),
        responses: [
            new OA\Response(response: 200, description: 'The updated staff member', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | founder_protected | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (including an email already used) | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateStaffRequest $request, string $staff, UpdateStaffAction $update): StaffMemberResource
    {
        $update->handle($request->user('staff'), $staff, $request->safe()->only(['full_name', 'email', 'phone']), $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($staff));
    }

    #[OA\Delete(
        path: '/dashboard/staff/{staff}',
        operationId: 'dashboardDeleteStaff',
        summary: 'Delete a staff member',
        description: 'A soft delete, final: the member leaves every list and lookup and cannot sign in, history keeps their name, and their email is free for a new account. Not yourself, never a founder; active or disabled. Every token is revoked and open set-password links are voided. A reason is required (body). Audited (authz.staff.deleted). Requires staff.manage and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [
            new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStaffStatusChange')),
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied (yourself) | founder_protected | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'last_role_manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'reason_required | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function destroy(StaffStatusChangeRequest $request, string $staff, DeleteStaffAction $delete): Response
    {
        $delete->handle($request->user('staff'), $staff, $request->validated('reason'));

        return response()->noContent();
    }

    #[OA\Put(
        path: '/dashboard/staff/{staff}/branch',
        operationId: 'dashboardSetStaffBranch',
        summary: 'Set or clear the branch a staff member works at',
        description: 'Not allowed on yourself. The branch must be enabled; null clears it. A reason is required. Audited (authz.staff.branch_changed). Takes effect on the target\'s next request. Requires roles.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Access Control'],
        parameters: [new OA\Parameter(name: 'staff', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSetStaffBranch')),
        responses: [
            new OA\Response(response: 200, description: 'Updated staff member', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffMember'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | escalation_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function updateBranch(SetStaffBranchRequest $request, string $staff, SetStaffBranchAction $set): StaffMemberResource
    {
        $branchId = $request->validated('branch_id');
        $set->handle($request->user('staff'), $this->staff->find($staff), $branchId === null ? null : (int) $branchId, $request->validated('reason'));

        return StaffMemberResource::make($this->staff->find($staff));
    }
}
