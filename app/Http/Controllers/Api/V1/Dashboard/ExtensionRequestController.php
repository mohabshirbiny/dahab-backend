<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Orders\Staff\AnswerExtensionRequestAction;
use App\Actions\Orders\Staff\ListExtensionRequestsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Order\AnswerExtensionRequestRequest;
use App\Http\Requests\Dashboard\Order\ListExtensionRequestsRequest;
use App\Http\Resources\Staff\ExtensionRequestResource;
use App\Models\OrderExtensionRequest;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Sellers' requests for more time, in Orders (spec 014 US4): *More time
 * requested* and *Extension requests*. Reading needs `order.extend_deadline`
 * or `order.view`; answering needs `order.extend_deadline`. Every POST is
 * idempotent and audited.
 */
class ExtensionRequestController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/extension-requests',
        operationId: 'dashboardExtensionRequests',
        summary: 'Sellers\' requests for more time',
        description: 'Spec 014 FR-024, FR-027. state: waiting (default) | accepted | refused | lapsed | all; month=YYYY-MM (Cairo). Oldest first, keyset-paginated. Each row: the order and its branch, the seller, the reason and what they wrote, the deadline now and the seconds left, the extensions before. Requires order.extend_deadline or order.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['waiting', 'accepted', 'refused', 'lapsed', 'all'])),
            new OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '2026-10')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of requests', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffExtensionRequest')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListExtensionRequestsRequest $request, ListExtensionRequestsAction $list): JsonResponse
    {
        $page = $list->handle($request->states(), $request->month(), $request->cursor(), $request->perPage());

        return response()->json([
            'data' => ExtensionRequestResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $request->perPage(), 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Post(
        path: '/dashboard/extension-requests/{extensionRequest}/accept',
        operationId: 'dashboardAcceptExtensionRequest',
        summary: 'Accept a request for more time',
        description: 'Spec 014 FR-024. The new reach-branch deadline is the current one plus the chosen working hours (6, 12, 24 or 48) at the branch of the order, written as a staff extension (order.deadline_extended) linked to the request; both parties are told the new deadline. Audited (order.extension_request_accepted). Idempotent. Requires order.extend_deadline.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AcceptExtensionRequestRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'extensionRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The request, accepted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffExtensionRequest')])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_extension_request_transition | deadline_not_running | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | deadline_must_move_forward | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function accept(AnswerExtensionRequestRequest $request, string $extensionRequest, AnswerExtensionRequestAction $answer): JsonResponse
    {
        $answer->accept($this->staff($request), $extensionRequest, (int) $request->validated('hours'),
            (string) $request->validated('note'), $request->attributes->get('context'));

        return $this->respond($request, $extensionRequest);
    }

    #[OA\Post(
        path: '/dashboard/extension-requests/{extensionRequest}/refuse',
        operationId: 'dashboardRefuseExtensionRequest',
        summary: 'Refuse a request for more time',
        description: 'Spec 014 FR-024. The deadline stays; the seller is told with your note (SMS and email). Audited (order.extension_request_refused). Idempotent. Requires order.extend_deadline.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RefuseExtensionRequestRequest')),
        tags: ['Dashboard Orders'],
        parameters: [
            new OA\Parameter(name: 'extensionRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The request, refused', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffExtensionRequest')])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_extension_request_transition | order_frozen | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function refuse(AnswerExtensionRequestRequest $request, string $extensionRequest, AnswerExtensionRequestAction $answer): JsonResponse
    {
        $answer->refuse($this->staff($request), $extensionRequest, (string) $request->validated('note'), $request->attributes->get('context'));

        return $this->respond($request, $extensionRequest);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }

    private function respond(Request $request, string $requestId): JsonResponse
    {
        $row = OrderExtensionRequest::query()->with([
            'order:order_id,order_ref,state,branch_id,seller_id,reach_branch_deadline',
            'order.branch:branch_id,name_en,name_ar', 'order.seller:customer_id,display_ref', 'answerer:staff_id,full_name',
        ])->findOrFail($requestId);

        return response()->json(['data' => ExtensionRequestResource::shape($row, withOrder: true)]);
    }
}
