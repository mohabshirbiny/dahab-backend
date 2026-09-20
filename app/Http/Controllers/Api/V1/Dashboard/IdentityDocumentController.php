<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Identity\ListIdentityDocumentsAction;
use App\Actions\Identity\ReviewIdentityDocumentAction;
use App\Actions\Identity\ShowIdentityDocumentAction;
use App\Actions\Identity\ViewIdentityDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\ListIdentityDocumentsRequest;
use App\Http\Requests\Identity\ReviewIdentityDocumentRequest;
use App\Http\Resources\Staff\IdentityDocumentResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class IdentityDocumentController extends Controller
{
    public function __construct(
        private readonly ListIdentityDocumentsAction $list,
        private readonly ShowIdentityDocumentAction $show,
        private readonly ViewIdentityDocumentAction $view,
        private readonly ReviewIdentityDocumentAction $review,
    ) {}

    #[OA\Get(
        path: '/dashboard/identity-documents',
        operationId: 'dashboardListIdentityDocuments',
        summary: 'List identity documents by status (oldest first)',
        description: 'Requires identity.view. Defaults to status=pending. Metadata only.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Identity'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'verified', 'needs_resubmission', 'rejected'], default: 'pending')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated documents', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffIdentityDocument')),
            ])),
        ],
    )]
    public function index(ListIdentityDocumentsRequest $request): AnonymousResourceCollection
    {
        return IdentityDocumentResource::collection(
            $this->list->handle($request->user('staff'), $request->status(), $request->perPage())
        );
    }

    #[OA\Get(
        path: '/dashboard/identity-documents/{document}',
        operationId: 'dashboardShowIdentityDocument',
        summary: 'Show an identity document\'s review metadata',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Identity'],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The document', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffIdentityDocument')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $document): IdentityDocumentResource
    {
        return IdentityDocumentResource::make($this->show->handle($request->user('staff'), $document));
    }

    #[OA\Get(
        path: '/dashboard/identity-documents/{document}/image',
        operationId: 'dashboardViewIdentityDocumentImage',
        summary: 'Open a side of the identity document (audited)',
        description: 'Requires identity.view. `side` selects front (default) or back. Every successful call writes a document_view_log row inside the same transaction as the read.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Identity'],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'side', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['front', 'back'], default: 'front')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Image bytes'),
            new OA\Response(response: 410, description: 'document_image_deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function image(Request $request, string $document): Response
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $side = $request->query('side', ViewIdentityDocumentAction::SIDE_FRONT);

        $image = $this->view->handle($request->user('staff'), $document, $side, $ctx);

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline; filename="identity-document-'.$side.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    #[OA\Post(
        path: '/dashboard/identity-documents/{document}/review',
        operationId: 'dashboardReviewIdentityDocument',
        summary: 'Verify / Ask again / Reject an identity document',
        description: 'Requires identity.review. `verify` sets the customer active. `request_resubmission` moves the document to needs_resubmission and keeps the customer at pending_verification. `reject` sets the customer rejected. Reasons are required for the two rejecting outcomes.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Identity'],
        parameters: [new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReviewIdentityDocumentRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Reviewed'),
            new OA\Response(response: 409, description: 'illegal_document_transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function review(ReviewIdentityDocumentRequest $request, string $document): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        $reviewed = $this->review->handle(
            $request->user('staff'),
            $document,
            $request->decision(),
            $request->reasons(),
            $request->note(),
            $ctx,
        );

        return IdentityDocumentResource::make($reviewed)->response();
    }
}
