<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Enums\PieceCategory;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Karat;
use App\Models\LegalDocument;
use App\Models\PieceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Reference data the apps need before anyone signs in (spec 010 FR-038,
 * FR-039): the karats, piece types and branches a seller can choose, and the
 * current text of a legal document. Public, read-only, rate limited. Only
 * enabled rows and only the fields listed — no purity, prices, hours or
 * staff.
 */
class ReferenceController extends Controller
{
    #[OA\Get(
        path: '/reference/karats',
        operationId: 'referenceKarats',
        summary: 'The karats a seller can choose',
        description: 'Spec 010 FR-038. Enabled karats in display order. No purity and no prices. Public.',
        tags: ['Reference'],
        responses: [
            new OA\Response(response: 200, description: 'Karats', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'code', type: 'integer', example: 21),
                    new OA\Property(property: 'sort_order', type: 'integer'),
                ], type: 'object')),
            ])),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function karats(): JsonResponse
    {
        return response()->json(['data' => Karat::query()->where('is_enabled', true)->ordered()->get()
            ->map(fn (Karat $k) => ['code' => $k->karat_code, 'sort_order' => $k->sort_order])->all()]);
    }

    #[OA\Get(
        path: '/reference/piece-types',
        operationId: 'referencePieceTypes',
        summary: 'The kinds of piece a seller can list',
        description: 'Spec 010 FR-038. Enabled piece types, optionally of one category, with English and Arabic names and the typical weight range where one is known. Public.',
        tags: ['Reference'],
        parameters: [new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']))],
        responses: [
            new OA\Response(response: 200, description: 'Piece types', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'category', type: 'string', enum: ['gold', 'diamond', 'gold_with_diamond']),
                    new OA\Property(property: 'name_en', type: 'string', example: 'Ring'),
                    new OA\Property(property: 'name_ar', type: 'string'),
                    new OA\Property(property: 'typical_min_g', type: 'string', nullable: true, example: '3.000'),
                    new OA\Property(property: 'typical_max_g', type: 'string', nullable: true, example: '5.000'),
                ], type: 'object')),
            ])),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function pieceTypes(Request $request): JsonResponse
    {
        $filters = $request->validate(['category' => ['sometimes', Rule::enum(PieceCategory::class)]]);

        $types = PieceType::query()->where('is_enabled', true)
            ->when(isset($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->orderBy('category')->orderBy('piece_type_id')->get();

        return response()->json(['data' => $types->map(fn (PieceType $t) => [
            'id' => $t->piece_type_id,
            'category' => $t->category->value,
            'name_en' => $t->name_en,
            'name_ar' => $t->name_ar,
            'typical_min_g' => $t->typical_min_g,
            'typical_max_g' => $t->typical_max_g,
        ])->all()]);
    }

    #[OA\Get(
        path: '/reference/branches',
        operationId: 'referenceBranches',
        summary: 'The inspection branches a seller can name',
        description: 'Spec 010 FR-038. Enabled branches with their English and Arabic name and address. Public.',
        tags: ['Reference'],
        responses: [
            new OA\Response(response: 200, description: 'Branches', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'name_en', type: 'string'),
                    new OA\Property(property: 'name_ar', type: 'string'),
                    new OA\Property(property: 'address_en', type: 'string'),
                    new OA\Property(property: 'address_ar', type: 'string'),
                ], type: 'object')),
            ])),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function branches(): JsonResponse
    {
        return response()->json(['data' => Branch::query()->where('is_enabled', true)->orderBy('branch_id')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->branch_id,
                'name_en' => $b->name_en,
                'name_ar' => $b->name_ar,
                'address_en' => $b->address_en,
                'address_ar' => $b->address_ar,
            ])->all()]);
    }

    #[OA\Get(
        path: '/reference/legal-documents/{code}',
        operationId: 'referenceLegalDocument',
        summary: 'The current version of a legal text',
        description: 'Spec 010 FR-039. The highest version of the document with this code, e.g. `ownership_declaration` — the text a seller ticks when listing a piece. Send its `id` as `ownership_legal_doc_id`. Public.',
        tags: ['Reference'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'ownership_declaration'))],
        responses: [
            new OA\Response(response: 200, description: 'The document', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'version', type: 'integer'),
                    new OA\Property(property: 'body_en', type: 'string'),
                    new OA\Property(property: 'body_ar', type: 'string'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function legalDocument(string $code): JsonResponse
    {
        $doc = LegalDocument::current($code) ?? abort(404);

        return response()->json(['data' => [
            'id' => $doc->legal_doc_id,
            'code' => $doc->code,
            'version' => $doc->version,
            'body_en' => $doc->body_en,
            'body_ar' => $doc->body_ar,
        ]]);
    }
}
