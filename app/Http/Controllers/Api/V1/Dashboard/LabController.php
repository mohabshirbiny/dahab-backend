<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Reference\SaveLabAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Reference\SaveLabRequest;
use App\Http\Resources\Reference\LabResource;
use App\Models\Lab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Labs and what they cost the seller (docs/features/labs-and-certificate-costs.md).
 * Viewing needs reference.view; changes need branches.manage and are audited.
 */
class LabController extends Controller
{
    #[OA\Get(
        path: '/dashboard/labs',
        operationId: 'dashboardListLabs',
        summary: 'List labs',
        description: 'Enabled and disabled labs, the main lab first. Requires reference.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        responses: [
            new OA\Response(response: 200, description: 'Labs', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardLab')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return LabResource::collection(Lab::query()->orderBy('lab_id')->get());
    }

    #[OA\Post(
        path: '/dashboard/labs',
        operationId: 'dashboardCreateLab',
        summary: 'Add a lab',
        description: 'Audited (reference.lab.created). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSaveLab')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardLab'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(SaveLabRequest $request, SaveLabAction $save): JsonResponse
    {
        return LabResource::make($save->create($request->user('staff'), $request->validated()))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/dashboard/labs/{lab}',
        operationId: 'dashboardUpdateLab',
        summary: 'Edit a lab, its costs, or disable it',
        description: 'A changed cost applies to pieces not yet accepted and their sellers are told. Audited (reference.lab.updated). Requires branches.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        parameters: [new OA\Parameter(name: 'lab', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSaveLab')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardLab'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(SaveLabRequest $request, int $lab, SaveLabAction $save): LabResource
    {
        return LabResource::make($save->update($request->user('staff'), $lab, $request->validated()));
    }
}
