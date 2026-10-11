<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Legal\PublishLegalVersionAction;
use App\Enums\LegalDocumentCode;
use App\Exceptions\DomainApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Content\PublishLegalVersionRequest;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffLegalDocument',
    description: 'One legal document code with its live version (spec 019).',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['document', 'declaration']),
        new OA\Property(property: 'published', type: 'boolean'),
        new OA\Property(property: 'version', type: 'integer', nullable: true),
        new OA\Property(property: 'legal_doc_id', type: 'integer', nullable: true),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'published_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'is_material', type: 'boolean', nullable: true),
        new OA\Property(property: 'accepted_live_count', type: 'integer'),
    ],
)]
#[OA\Schema(
    schema: 'StaffLegalVersion',
    description: 'One published version of a legal document (spec 019).',
    properties: [
        new OA\Property(property: 'legal_doc_id', type: 'integer'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'is_material', type: 'boolean'),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'published_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'accepted_count', type: 'integer'),
        new OA\Property(property: 'body_en', type: 'string', description: 'Only with with_body=1'),
        new OA\Property(property: 'body_ar', type: 'string', description: 'Only with with_body=1'),
    ],
)]
/**
 * Terms and versions (spec 019 US3). Reading needs `legal.publish` or `content.edit`;
 * publishing a new version needs `legal.publish` alone — the CEO's, by seed (D1).
 */
class LegalDocumentController extends Controller
{
    #[OA\Get(
        path: '/dashboard/legal-documents',
        operationId: 'dashboardLegalDocuments',
        summary: 'The legal documents, their live version and how many accepted it',
        description: 'Spec 019 FR-023. Every document code with its kind (document | declaration), live version, published date and publisher, whether the version is material, and how many customers accepted the live version. Requires legal.publish or content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Legal'],
        responses: [
            new OA\Response(response: 200, description: 'The documents', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffLegalDocument')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): JsonResponse
    {
        $live = LegalDocument::query()->orderBy('code')->orderByDesc('version')->get()->groupBy('code')->map->first();
        $names = DB::table('staff')->whereIn('staff_id', $live->pluck('published_by')->filter()->all())->pluck('full_name', 'staff_id');
        $counts = DB::table('agreement_acceptance')->whereIn('legal_doc_id', $live->pluck('legal_doc_id')->all())
            ->groupBy('legal_doc_id')->selectRaw('legal_doc_id, COUNT(*) AS n')->pluck('n', 'legal_doc_id');

        return response()->json(['data' => array_map(function (LegalDocumentCode $code) use ($live, $names, $counts) {
            $doc = $live->get($code->value);

            return [
                'code' => $code->value,
                'label' => $code->label(),
                'kind' => $code->kind(),
                'published' => $doc !== null,
                'version' => $doc?->version,
                'legal_doc_id' => $doc?->legal_doc_id,
                'published_at' => $doc?->published_at?->toIso8601String(),
                'published_by' => $doc === null ? null : ['id' => $doc->published_by, 'full_name' => $names[$doc->published_by] ?? null],
                'is_material' => $doc === null ? null : (bool) $doc->is_material,
                'accepted_live_count' => $doc === null ? 0 : (int) ($counts[$doc->legal_doc_id] ?? 0),
            ];
        }, LegalDocumentCode::cases())]);
    }

    #[OA\Get(
        path: '/dashboard/legal-documents/{code}/versions',
        operationId: 'dashboardLegalDocumentVersions',
        summary: 'Every version of one legal document',
        description: 'Spec 019 FR-023. Newest first, with who published each and when, whether it was material and how many customers accepted it. `with_body=1` adds the texts. Requires legal.publish or content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Legal'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'terms')),
            new OA\Parameter(name: 'with_body', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The versions', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffLegalVersion')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'legal_code_unknown', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function versions(Request $request, string $code): JsonResponse
    {
        $enum = LegalDocumentCode::tryFrom($code) ?? throw DomainApiException::legalCodeUnknown();

        $rows = LegalDocument::query()->where('code', $enum->value)->orderByDesc('version')->get();
        $names = DB::table('staff')->whereIn('staff_id', $rows->pluck('published_by')->all())->pluck('full_name', 'staff_id');
        $counts = DB::table('agreement_acceptance')->whereIn('legal_doc_id', $rows->pluck('legal_doc_id')->all())
            ->groupBy('legal_doc_id')->selectRaw('legal_doc_id, COUNT(*) AS n')->pluck('n', 'legal_doc_id');
        $withBody = $request->boolean('with_body');

        return response()->json(['data' => $rows->map(fn (LegalDocument $d) => [
            'legal_doc_id' => $d->legal_doc_id,
            'version' => $d->version,
            'is_material' => (bool) $d->is_material,
            'published_at' => $d->published_at?->toIso8601String(),
            'published_by' => ['id' => $d->published_by, 'full_name' => $names[$d->published_by] ?? null],
            'accepted_count' => (int) ($counts[$d->legal_doc_id] ?? 0),
        ] + ($withBody ? ['body_en' => $d->body_en, 'body_ar' => $d->body_ar] : []))->values()->all()]);
    }

    #[OA\Post(
        path: '/dashboard/legal-documents/{code}/versions',
        operationId: 'dashboardPublishLegalVersion',
        summary: 'Publish a new version of a legal document',
        description: 'Spec 019 FR-021, FR-030. A whole new version (previous + 1) with both languages and `is_material`; earlier versions are untouched forever. Publishing `terms` or `privacy` asks every customer who has not accepted it, or accepted an older material version, to accept before they continue — publish only once the app with the acceptance screen is live. Audited (legal.published) with the length and sha256 of each text. Requires legal.publish (the CEO by seed) and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardPublishLegalVersion')),
        tags: ['Dashboard Legal'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'terms'))],
        responses: [
            new OA\Response(response: 201, description: 'Published', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'legal_doc_id', type: 'integer'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'version', type: 'integer'),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'is_material', type: 'boolean'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'legal_code_unknown', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'legal_version_conflict', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | legal_body_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function publish(PublishLegalVersionRequest $request, string $code, PublishLegalVersionAction $publish): JsonResponse
    {
        $result = $publish->handle(
            $request->user('staff'),
            $code,
            $request->validated('body_en'),
            $request->validated('body_ar'),
            $request->boolean('is_material'),
            $request->attributes->get('context'),
        );

        return response()->json(['data' => $result], 201);
    }
}
