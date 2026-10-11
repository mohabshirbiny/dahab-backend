<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Notices\ListStaffNoticesAction;
use App\Actions\Notices\MarkStaffNoticeReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Notices\ListStaffNoticesRequest;
use App\Http\Resources\Staff\StaffNoticeResource;
use App\Models\Staff;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard Notices', description: 'Dashboard API — your own Dashboard notices, the bell (spec 021). Any staff member; no permission.')]
/**
 * Dashboard notices, the bell (spec 021 research R12). Any staff member, own notices only, no
 * permission; not audited. Spec 021 writes the founder alerts; spec 024 adds its own kinds.
 */
class StaffNoticeController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/notices',
        operationId: 'dashboardNotices',
        summary: 'Your Dashboard notices, newest first (spec 021)',
        description: '`unread=1` keeps the unread ones; `meta.unread_count` is for the bell. Own notices only; no permission needed.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Notices'],
        parameters: [
            new OA\Parameter(name: 'unread', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated notices', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardStaffNotice')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'current_page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'unread_count', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListStaffNoticesRequest $request, ListStaffNoticesAction $notices): AnonymousResourceCollection
    {
        $staff = $this->staff($request);

        return StaffNoticeResource::collection($notices->handle($staff, $request->unreadOnly(), $request->perPage()))
            ->additional(['meta' => ['unread_count' => $notices->unreadCount($staff)]]);
    }

    #[OA\Post(
        path: '/dashboard/notices/{notice}/read',
        operationId: 'dashboardNoticeRead',
        summary: 'Mark one of your notices read (spec 021)',
        description: 'A notice already read keeps its time. Someone else\'s notice → 404.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Notices'],
        parameters: [new OA\Parameter(name: 'notice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The notice', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardStaffNotice'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function read(Request $request, string $notice, MarkStaffNoticeReadAction $mark): JsonResponse
    {
        return ApiResponse::ok((new StaffNoticeResource($mark->one($this->staff($request), $notice)))->resolve($request));
    }

    #[OA\Post(
        path: '/dashboard/notices/read-all',
        operationId: 'dashboardNoticesReadAll',
        summary: 'Mark every notice of yours read (spec 021)',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Notices'],
        responses: [
            new OA\Response(response: 200, description: 'How many were marked', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'marked', type: 'integer')], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function readAll(Request $request, MarkStaffNoticeReadAction $mark): JsonResponse
    {
        return ApiResponse::ok(['marked' => $mark->all($this->staff($request))]);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
