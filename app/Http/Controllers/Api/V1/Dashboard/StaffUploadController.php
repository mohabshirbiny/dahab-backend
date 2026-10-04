<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Finance\CreateStaffUploadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Finance\StoreStaffUploadRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/** Staff uploads (spec 015 research R5): the proof of a bank movement. */
class StaffUploadController extends Controller
{
    #[OA\Post(
        path: '/dashboard/uploads',
        operationId: 'dashboardStaffUpload',
        summary: 'Upload a file for a staff action',
        description: 'Spec 015. purpose=bank_movement_proof (PDF/JPG/PNG, at most 10 MB), stored encrypted. Returns a single-use token tied to you and the purpose, passed as proof_upload_token to POST /dashboard/bank-movements. bank.record; throttle dashboard.uploads.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['purpose', 'file'],
            properties: [
                new OA\Property(property: 'purpose', type: 'string', enum: ['bank_movement_proof']),
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
            ],
        ))),
        tags: ['Dashboard Finance'],
        responses: [
            new OA\Response(response: 201, description: 'Stored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreStaffUploadRequest $request, CreateStaffUploadAction $upload): JsonResponse
    {
        return response()->json(['data' => $upload->handle($request->user('staff'), $request->purpose(), $request->file('file'))], 201);
    }
}
