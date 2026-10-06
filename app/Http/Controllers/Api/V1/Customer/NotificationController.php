<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Notifications\InboxAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\InboxItemResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The customer's in-app inbox (spec 017 US4, FR-030–FR-032): every message
 * Dahab sends by SMS or email, except codes and confirmation links, with a
 * link to what it is about. Every signed-in customer, whatever the state.
 */
class NotificationController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/notifications',
        operationId: 'customerInbox',
        summary: 'The inbox, newest first',
        description: 'Spec 017 FR-032. Keyset paged; `unread=1` keeps the unread ones; meta carries the unread count.',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unread', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Items', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InboxItem')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'unread_count', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'validation_failed (cursor)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, InboxAction $inbox): JsonResponse
    {
        $request->validate(['unread' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'cursor' => ['sometimes', 'string', 'max:200']]);
        $page = $inbox->page($this->customer($request)->customer_id, $request->query('cursor'),
            $request->boolean('unread'), (int) $request->query('per_page', 20));

        return response()->json([
            'data' => InboxItemResource::collection($page['items'])->resolve($request),
            'meta' => ['next_cursor' => $page['next_cursor'], 'unread_count' => $page['unread_count']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/notifications/unread-count',
        operationId: 'customerInboxUnreadCount',
        summary: 'How many inbox items are unread (the bell)',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        responses: [new OA\Response(response: 200, description: 'Count', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', properties: [new OA\Property(property: 'unread_count', type: 'integer')], type: 'object'),
        ]))],
    )]
    public function unreadCount(Request $request, InboxAction $inbox): JsonResponse
    {
        return ApiResponse::ok(['unread_count' => $inbox->unreadCount($this->customer($request)->customer_id)]);
    }

    #[OA\Post(
        path: '/customer/me/notifications/{notification}/read',
        operationId: 'customerInboxMarkRead',
        summary: 'Mark one inbox item read',
        description: 'Idempotent: an item already read keeps its time.',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The item', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InboxItem')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function read(Request $request, string $notification, InboxAction $inbox): JsonResponse
    {
        return ApiResponse::ok((new InboxItemResource($inbox->markRead($this->customer($request)->customer_id, $notification)))->resolve($request));
    }

    #[OA\Post(
        path: '/customer/me/notifications/read-all',
        operationId: 'customerInboxMarkAllRead',
        summary: 'Mark every inbox item read',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'How many were marked', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', properties: [new OA\Property(property: 'marked', type: 'integer')], type: 'object'),
        ]))],
    )]
    public function readAll(Request $request, InboxAction $inbox): JsonResponse
    {
        return ApiResponse::ok(['marked' => $inbox->markAllRead($this->customer($request)->customer_id)]);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
