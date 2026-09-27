<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Reference\CreateBranchAction;
use App\Actions\Reference\UpdateBranchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Reference\StoreBranchRequest;
use App\Http\Requests\Dashboard\Reference\UpdateBranchRequest;
use App\Http\Resources\Reference\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Inspection branches and their weekly hours (spec 004 User Story 1).
 * Viewing needs reference.view; changes need branches.manage and are audited.
 */
#[OA\Tag(name: 'Dashboard Reference Data', description: 'Dashboard API — karats, branches, weekly hours and closures (spec 004). Viewing: reference.view.')]
class BranchController extends Controller
{
    #[OA\Get(
        path: '/dashboard/branches',
        operationId: 'dashboardListBranches',
        summary: 'List branches with their weekly hours',
        description: 'Enabled and disabled branches, ordered by name_en. Requires reference.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        responses: [
            new OA\Response(response: 200, description: 'Branches', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranch')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->with('hours')->orderBy('name_en')->orderBy('branch_id')->get());
    }

    #[OA\Post(
        path: '/dashboard/branches',
        operationId: 'dashboardCreateBranch',
        summary: 'Add a branch with its weekly hours',
        description: 'Audited (reference.branch.created). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStoreBranch')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardBranch'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (hours.N: order or overlap; timezone)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreBranchRequest $request, CreateBranchAction $create): JsonResponse
    {
        $branch = $create->handle($request->user('staff'), $request->validated());

        return BranchResource::make($branch->load('hours'))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/dashboard/branches/{branch}',
        operationId: 'dashboardUpdateBranch',
        summary: 'Edit a branch, replace its week, or disable it',
        description: '`hours`, when present, replaces the whole week. Branches are never deleted; set is_enabled false. Audited (reference.branch.updated, reference.branch.hours_replaced). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateBranch')),
        responses: [
            new OA\Response(response: 200, description: 'Updated branch', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardBranch'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateBranchRequest $request, int $branch, UpdateBranchAction $update): BranchResource
    {
        $updated = $update->handle($request->user('staff'), $branch, $request->validated());

        return BranchResource::make($updated->load('hours'));
    }
}
