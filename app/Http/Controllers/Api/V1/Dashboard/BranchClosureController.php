<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Reference\AddBranchClosureAction;
use App\Actions\Reference\RemoveBranchClosureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Reference\StoreBranchClosureRequest;
use App\Http\Resources\Reference\BranchClosureResource;
use App\Models\BranchClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Full-day closures and all-branch public holidays (spec 004 User Story 1).
 * They contribute zero working time to every deadline (Part 3 §1.2).
 */
class BranchClosureController extends Controller
{
    #[OA\Get(
        path: '/dashboard/branch-closures',
        operationId: 'dashboardListBranchClosures',
        summary: 'List closures and public holidays',
        description: 'Past and future, date ascending. Requires reference.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        responses: [
            new OA\Response(response: 200, description: 'Closures', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranchClosure')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return BranchClosureResource::collection(
            BranchClosure::query()->orderBy('closure_date')->orderByRaw('branch_id NULLS FIRST')->get(),
        );
    }

    #[OA\Post(
        path: '/dashboard/branch-closures',
        operationId: 'dashboardAddBranchClosure',
        summary: 'Close a branch, or every branch, for a day',
        description: 'branch_id null = every branch. The date must be today or later. Audited (reference.closure.added). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStoreBranchClosure')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardBranchClosure'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'closure_exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (past date, unknown branch)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreBranchClosureRequest $request, AddBranchClosureAction $add): JsonResponse
    {
        $data = $request->validated();
        $data['branch_id'] = $data['branch_id'] === null ? null : (int) $data['branch_id'];

        return BranchClosureResource::make($add->handle($request->user('staff'), $data))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/dashboard/branch-closures/{closure}',
        operationId: 'dashboardRemoveBranchClosure',
        summary: 'Remove a future closure',
        description: 'Only closures dated after today can be removed. Audited (reference.closure.removed). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        parameters: [new OA\Parameter(name: 'closure', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'closure_in_past', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function destroy(Request $request, int $closure, RemoveBranchClosureAction $remove): Response
    {
        $remove->handle($request->user('staff'), $closure);

        return response()->noContent();
    }
}
