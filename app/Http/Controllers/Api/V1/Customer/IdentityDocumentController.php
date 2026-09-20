<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Identity\SubmitIdentityDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\SubmitIdentityDocumentRequest;
use App\Http\Resources\Customer\IdentityDocumentResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class IdentityDocumentController extends Controller
{
    public function __construct(private readonly SubmitIdentityDocumentAction $submit) {}

    #[OA\Post(
        path: '/customer/me/identity-documents',
        operationId: 'customerSubmitIdentityDocument',
        summary: 'Submit or resubmit an identity document',
        description: 'Creates a pending document on first submit. If the customer\'s existing document is in `needs_resubmission`, the same row is updated in place (no duplicate customer, no duplicate audit trail).',
        security: [['customerBearer' => []]],
        tags: ['Customer Identity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubmitIdentityDocumentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/IdentityDocument')])),
            new OA\Response(response: 409, description: 'document_already_pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed, unsupported_doc_kind, upload_token_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(SubmitIdentityDocumentRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        $document = $this->submit->handle(
            $request->user('customer'),
            $request->validated('doc_kind'),
            $request->validated('front_upload_token'),
            $request->validated('back_upload_token'),
            $ctx,
        );

        return IdentityDocumentResource::make($document)->response()->setStatusCode(201);
    }
}
