<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Actions\Reference\QuoteAction;
use App\Actions\Reference\ShowGoldPricesAction;
use App\Enums\LegalDocumentCode;
use App\Enums\PieceCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\QuoteRequest;
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
        path: '/reference/legal-documents',
        operationId: 'referenceLegalDocuments',
        summary: 'The legal documents the app lists, published or not yet',
        description: 'Spec 017 FR-045. terms, privacy, selling_rules, id_handling: the latest version when published (read it at /reference/legal-documents/{code}), else published false. Public.',
        tags: ['Reference'],
        responses: [new OA\Response(response: 200, description: 'The list', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'code', type: 'string', enum: ['terms', 'privacy', 'selling_rules', 'id_handling']),
                new OA\Property(property: 'published', type: 'boolean'),
                new OA\Property(property: 'version', type: 'integer', nullable: true),
                new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
            ], type: 'object')),
        ]))],
    )]
    public function legalDocuments(): JsonResponse
    {
        return response()->json(['data' => array_map(function (LegalDocumentCode $code) {
            $doc = LegalDocument::current($code->value);

            return [
                'code' => $code->value,
                'published' => $doc !== null,
                'version' => $doc?->version,
                'published_at' => $doc?->published_at?->toIso8601String(),
            ];
        }, LegalDocumentCode::cases())]);
    }

    #[OA\Get(
        path: '/reference/support-contacts',
        operationId: 'referenceSupportContacts',
        summary: 'How to reach Dahab',
        description: 'Spec 017 FR-045. From the Backend configuration (demo values until replaced before production). Public.',
        tags: ['Reference'],
        responses: [new OA\Response(response: 200, description: 'Contacts', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'phone', type: 'string', example: '16000'),
                new OA\Property(property: 'hours_en', type: 'string'),
                new OA\Property(property: 'hours_ar', type: 'string'),
                new OA\Property(property: 'whatsapp', type: 'string'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'social', properties: [
                    new OA\Property(property: 'facebook', type: 'string'),
                    new OA\Property(property: 'instagram', type: 'string'),
                    new OA\Property(property: 'tiktok', type: 'string'),
                ], type: 'object'),
            ], type: 'object'),
        ]))],
    )]
    public function supportContacts(): JsonResponse
    {
        $c = config('dahab-support');

        return response()->json(['data' => [
            'phone' => (string) $c['phone'],
            'hours_en' => (string) $c['hours_en'],
            'hours_ar' => (string) $c['hours_ar'],
            'whatsapp' => (string) $c['whatsapp'],
            'email' => (string) $c['email'],
            'social' => array_map('strval', $c['social']),
        ]]);
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

    #[OA\Get(
        path: '/reference/gold-prices',
        operationId: 'referenceGoldPrices',
        summary: 'Today\'s gold prices',
        description: 'Spec 015 FR-021. Public, limiter public.market, cacheable for 30 s. Per enabled karat what sellers get and what buyers pay per gram (the Part 3 §2 calculator), the time of the price and the feed state (live, manual, stale). Never the provider\'s bid/ask or the adjustments. price_unavailable (409) when no price can be used.',
        tags: ['Reference'],
        responses: [
            new OA\Response(response: 200, description: 'The prices', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'price_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'feed_state', type: 'string', enum: ['live', 'manual', 'stale']),
                new OA\Property(property: 'karats', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'code', type: 'integer', example: 21),
                    new OA\Property(property: 'label', type: 'string', example: '21K'),
                    new OA\Property(property: 'sellers_get', type: 'string'),
                    new OA\Property(property: 'buyers_pay', type: 'string'),
                ], type: 'object')),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'price_unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function goldPrices(ShowGoldPricesAction $prices): JsonResponse
    {
        return response()->json(['data' => $prices->handle()])->header('Cache-Control', 'public, max-age=30');
    }

    #[OA\Get(
        path: '/reference/quote',
        operationId: 'referenceQuote',
        summary: 'What a seller would get for a piece today',
        description: 'Spec 015 FR-021. Public, limiter public.market. The indicative estimate from the Part 3 §2 calculator: gold value, the making charge back, commission (and whether the minimum applied), VAT and the payout. gold needs karat + weight_g (+ making_per_g); gold_with_diamond karat + weight_g + asking_price; diamond asking_price. Nothing is locked. price_unavailable (409) when no gold price can be used.',
        tags: ['Reference'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['gold', 'gold_with_diamond', 'diamond'])),
            new OA\Parameter(name: 'karat', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 21)),
            new OA\Parameter(name: 'weight_g', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '10.000')),
            new OA\Parameter(name: 'making_per_g', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '300')),
            new OA\Parameter(name: 'asking_price', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The estimate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'category', type: 'string'),
                new OA\Property(property: 'rate_per_gram', type: 'string', nullable: true, description: 'What sellers get per gram of this karat'),
                new OA\Property(property: 'gold_value', type: 'string'),
                new OA\Property(property: 'making_back', type: 'string'),
                new OA\Property(property: 'asking_price', type: 'string', nullable: true),
                new OA\Property(property: 'commission', type: 'string'),
                new OA\Property(property: 'vat', type: 'string'),
                new OA\Property(property: 'payout', type: 'string'),
                new OA\Property(property: 'commission_rate', type: 'string', description: 'Percent'),
                new OA\Property(property: 'minimum_applied', type: 'boolean'),
                new OA\Property(property: 'indicative', type: 'boolean'),
                new OA\Property(property: 'price_at', type: 'string', format: 'date-time', nullable: true),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'price_unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function quote(QuoteRequest $request, QuoteAction $quote): JsonResponse
    {
        return response()->json(['data' => $quote->handle($request->category(), $request->validated('karat') === null ? null : (int) $request->validated('karat'),
            $request->validated('weight_g'), $request->validated('making_per_g'), $request->validated('asking_price'))])
            ->header('Cache-Control', 'public, max-age=30');
    }
}
