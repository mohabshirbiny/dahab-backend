<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Audit\ExportAuditEntriesAction;
use App\Actions\Audit\ListAuditEntriesAction;
use App\Actions\Audit\ShowAuditEntryAction;
use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Audit\AuditFilterRequest;
use App\Support\Audit\AuditCursor;
use App\Support\Audit\AuditFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The audit log viewer (spec 006). audit.view_all sees everything;
 * audit.view_own sees the caller's own actions only — enforced in the query.
 */
#[OA\Tag(name: 'Dashboard Audit', description: 'Dashboard API — the audit log (spec 006). Requires audit.view_all or audit.view_own; without view_all only your own actions are returned.')]
class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/dashboard/audit-log',
        operationId: 'dashboardListAuditLog',
        summary: 'List audit entries, newest first',
        description: 'Keyset-paginated: pass meta.next_cursor as `cursor`. `total` counts every match under the same filters and visibility.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Audit'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Cairo date; default: 6 days before `to`'),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Cairo date; default: today'),
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['money', 'pricing', 'accounts', 'identity', 'promo', 'reference', 'sessions', 'system']), description: 'Absent = Everything (all but sessions)'),
            new OA\Parameter(name: 'actor', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'A staff id, or `system`'),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'entity_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'entity_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Entries', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardAuditEntry')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'from', type: 'string', format: 'date'),
                    new OA\Property(property: 'to', type: 'string', format: 'date'),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(AuditFilterRequest $request, ListAuditEntriesAction $list): JsonResponse
    {
        $filters = AuditFilters::fromInput($request->validated());
        $perPage = (int) $request->validated('per_page', 50);
        $page = $list->handle($request->user('staff'), $filters, AuditCursor::decode($request->validated('cursor')), $perPage);

        return response()->json([
            'data' => $page['entries'],
            'meta' => [
                'total' => $page['total'],
                'per_page' => $perPage,
                'next_cursor' => $page['next_cursor'],
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
                'category' => $filters->category?->value,
            ],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/audit-log/categories',
        operationId: 'dashboardAuditCategories',
        summary: 'The audit categories and whether Everything includes them',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Audit'],
        responses: [
            new OA\Response(response: 200, description: 'Categories', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'value', type: 'string'),
                    new OA\Property(property: 'label', type: 'string'),
                    new OA\Property(property: 'in_everything', type: 'boolean'),
                ], type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function categories(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (AuditCategory $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'in_everything' => $c->inEverything(),
        ], AuditCategory::cases())]);
    }

    #[OA\Get(
        path: '/dashboard/audit-log/export',
        operationId: 'dashboardExportAuditLog',
        summary: 'Download the filtered audit log as CSV (opens in Excel)',
        description: 'Every matching entry under the same filters and visibility, capped (X-Export-Truncated: true when the cap cut it). UTF-8 with a BOM. Each export is itself recorded (audit.log.exported).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Audit'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Cairo date; default: 6 days before `to`'),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Cairo date; default: today'),
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['money', 'pricing', 'accounts', 'identity', 'promo', 'reference', 'sessions', 'system']), description: 'Absent = Everything (all but sessions)'),
            new OA\Parameter(name: 'actor', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'A staff id, or `system`'),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'entity_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'entity_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function export(AuditFilterRequest $request, ExportAuditEntriesAction $export): Response
    {
        $result = $export->handle($request->user('staff'), AuditFilters::fromInput($request->validated()));
        $name = 'audit-log-'.now()->format('Ymd-Hi').'.csv';

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Export-Truncated' => $result['truncated'] ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Truncated',
        ]);
    }

    #[OA\Get(
        path: '/dashboard/audit-log/{entry}',
        operationId: 'dashboardShowAuditEntry',
        summary: 'One audit entry with everything recorded for it',
        description: 'An entry the caller may not see answers 404, like a missing one.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Audit'],
        parameters: [new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Entry', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardAuditEntryDetail'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, int $entry, ShowAuditEntryAction $show): JsonResponse
    {
        return response()->json(['data' => $show->handle($request->user('staff'), $entry)]);
    }
}
