<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Identity\CreateCustomerUploadAction;
use App\Enums\UploadPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class UploadController extends Controller
{
    public function __construct(private readonly CreateCustomerUploadAction $createUpload) {}

    #[OA\Post(
        path: '/customer/me/uploads',
        operationId: 'customerCreateUpload',
        summary: 'Upload a private image and receive a single-use upload token',
        description: 'The image is encrypted and stored on a private disk that has no URL. Reference it once, by its `upload_token`, from the endpoint that consumes it (for purpose `identity`: `POST /customer/me/identity-documents`). An unclaimed upload expires after `expires_in` seconds.',
        security: [['customerBearer' => []]],
        tags: ['Customer Identity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/CreateUploadRequest'))),
        responses: [
            new OA\Response(response: 201, description: 'Stored', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'upload_token', type: 'string'),
                    new OA\Property(property: 'purpose', type: 'string', enum: ['identity']),
                    new OA\Property(property: 'expires_in', type: 'integer', description: 'Seconds until an unclaimed token expires'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated — no valid customer token', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — a refresh token was presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed — unknown purpose, not an image, or too large', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreUploadRequest $request): JsonResponse
    {
        $purpose = UploadPurpose::from($request->validated('purpose'));

        $upload = $this->createUpload->handle($request->user('customer'), $purpose, $request->file('file'));

        return response()->json(['data' => [
            'upload_token' => $upload['token'],
            'purpose' => $purpose->value,
            'expires_in' => $upload['expires_in'],
        ]], 201);
    }
}
