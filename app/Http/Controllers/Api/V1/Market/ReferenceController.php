<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Actions\Reference\QuoteAction;
use App\Actions\Reference\ShowGoldPricesAction;
use App\Enums\LegalDocumentCode;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\QuoteRequest;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Branch;
use App\Models\Karat;
use App\Models\LegalDocument;
use App\Models\PieceType;
use App\Support\Content\BundleBuilder;
use App\Support\Controls\ControlsSnapshot;
use App\Support\Pricing\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        description: 'Spec 010 FR-038. Enabled branches with their English and Arabic name and address, phone, map link and weekly hours (dow 0 = Sunday). Public.',
        tags: ['Reference'],
        responses: [
            new OA\Response(response: 200, description: 'Branches', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'name_en', type: 'string'),
                    new OA\Property(property: 'name_ar', type: 'string'),
                    new OA\Property(property: 'address_en', type: 'string'),
                    new OA\Property(property: 'address_ar', type: 'string'),
                    new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
                    new OA\Property(property: 'map_url', type: 'string', nullable: true),
                    new OA\Property(property: 'hours', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranchHour')),
                ], type: 'object')),
            ])),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function branches(): JsonResponse
    {
        return response()->json(['data' => Branch::query()->with('hours')->where('is_enabled', true)->orderBy('branch_id')->get()
            ->map(fn (Branch $b) => CustomerOrderResource::branch($b))->all()]);
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
            new OA\Property(property: 'declarations', type: 'array', description: 'Spec 019: the declarations ticked inside a form (ownership, deposit, collection authorisation, payout account), with the live version.', items: new OA\Items(properties: [
                new OA\Property(property: 'code', type: 'string', enum: ['ownership_declaration', 'deposit_agreement', 'collection_proxy_authorisation', 'payout_account_declaration']),
                new OA\Property(property: 'published', type: 'boolean'),
                new OA\Property(property: 'version', type: 'integer', nullable: true),
                new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
            ], type: 'object')),
        ]))],
    )]
    public function legalDocuments(): JsonResponse
    {
        $row = function (LegalDocumentCode $code) {
            $doc = LegalDocument::current($code->value);

            return [
                'code' => $code->value,
                'published' => $doc !== null,
                'version' => $doc?->version,
                'published_at' => $doc?->published_at?->toIso8601String(),
            ];
        };

        return response()->json([
            'data' => array_map($row, LegalDocumentCode::documents()),
            'declarations' => array_map($row, LegalDocumentCode::declarations()),
        ]);
    }

    #[OA\Get(
        path: '/reference/controls',
        operationId: 'referenceControls',
        summary: 'What is stopped or paused, and what customers can see',
        description: 'Spec 019 US7. Whether everything is stopped (with the banner text), the paused or stopped categories with their messages, and the four visibility flags. Public, read-only, rate limited.',
        tags: ['Reference'],
        responses: [
            new OA\Response(response: 200, description: 'The switches'),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function controls(ControlsSnapshot $snapshot): JsonResponse
    {
        return response()->json(['data' => $snapshot->customer()]);
    }

    #[OA\Get(
        path: '/reference/app-texts',
        operationId: 'referenceAppTexts',
        summary: 'The texts staff published for the customer app',
        description: 'Spec 019 FR-006. Published overrides of the app and FAQ kinds only, in both languages (never notification templates, never a reverted key), the FAQ entries in order, and the current value of every setting placeholder. The version tag (`ETag`, also `data.tag`) is `"<bundle_version>-<settings fingerprint>"`: it changes when staff publish or when a number a text may state changes; `If-None-Match` with the same tag answers 304. The app keeps the last bundle and falls back to its own text on any failure. Public.',
        tags: ['Reference'],
        parameters: [new OA\Parameter(name: 'If-None-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The bundle', headers: [new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'tag', type: 'string', example: '"3-9c1d…"'),
                    new OA\Property(property: 'version', type: 'integer', description: '0 before the first publish'),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'texts', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [
                        new OA\Property(property: 'en', type: 'string'),
                        new OA\Property(property: 'ar', type: 'string'),
                    ], type: 'object')),
                    new OA\Property(property: 'faq', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'key', type: 'string'),
                        new OA\Property(property: 'position', type: 'integer', nullable: true),
                        new OA\Property(property: 'question', properties: [new OA\Property(property: 'en', type: 'string'), new OA\Property(property: 'ar', type: 'string')], type: 'object'),
                        new OA\Property(property: 'answer', properties: [new OA\Property(property: 'en', type: 'string'), new OA\Property(property: 'ar', type: 'string')], type: 'object'),
                    ], type: 'object')),
                    new OA\Property(property: 'settings', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['commission_gold_pct' => '20']),
                ], type: 'object'),
            ])),
            new OA\Response(response: 304, description: 'Not modified'),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function appTexts(Request $request, BundleBuilder $bundle): JsonResponse|Response
    {
        $tag = $bundle->tag();
        $headers = ['ETag' => $tag['tag'], 'Cache-Control' => 'public, max-age=0, must-revalidate', 'Access-Control-Expose-Headers' => 'ETag'];

        if (self::matches((string) $request->header('If-None-Match', ''), $tag['tag'])) {
            return response('', 304, $headers);
        }

        $content = $bundle->content();

        return response()->json(['data' => [
            'tag' => $tag['tag'],
            'version' => $tag['version'],
            'published_at' => $tag['published_at'],
            // Empty maps must stay JSON objects, not lists.
            'texts' => (object) $content['texts'],
            'faq' => $content['faq'],
            'settings' => (object) $tag['settings'],
        ]], 200, $headers);
    }

    /** `If-None-Match` may carry several tags, weak or not. */
    private static function matches(string $header, string $tag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== '' && $candidate === $tag) {
                return true;
            }
        }

        return false;
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
        path: '/reference/certificate-costs',
        operationId: 'referenceCertificateCosts',
        summary: 'What IGI\'s certificate costs the seller, by the certificate they bring',
        description: 'Part 3 §5 / OI-3.2. From the settings certificate.igi_cost_egp, certificate.other_lab_cost_egp and certificate.none_cost_egp (the Dashboard\'s Rates page). For the sell form of a diamond or gold with diamond; not yet taken at settlement. Public.',
        tags: ['Reference'],
        responses: [new OA\Response(response: 200, description: 'Costs in EGP', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'igi', type: 'string', example: '0.0000'),
                new OA\Property(property: 'other_lab', type: 'string', example: '1000.0000'),
                new OA\Property(property: 'none', type: 'string', example: '2000.0000'),
            ], type: 'object'),
        ]))],
    )]
    public function certificateCosts(Settings $settings): JsonResponse
    {
        return response()->json(['data' => [
            'igi' => $settings->numeric(SettingKey::CERTIFICATE_IGI_COST_EGP),
            'other_lab' => $settings->numeric(SettingKey::CERTIFICATE_OTHER_LAB_COST_EGP),
            'none' => $settings->numeric(SettingKey::CERTIFICATE_NONE_COST_EGP),
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
            new OA\Parameter(name: 'certificate', in: 'query', required: false, description: 'Stone pieces only: the certificate the seller brings; its cost (GET /reference/certificate-costs) is taken out of the payout.', schema: new OA\Schema(type: 'string', enum: ['igi', 'other_lab', 'none'])),
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
                new OA\Property(property: 'certificate_fee', type: 'string', description: 'The certificate cost taken out of the payout (0 when no certificate is named)'),
                new OA\Property(property: 'payout', type: 'string', description: 'After commission, VAT and the certificate fee'),
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
            $request->validated('weight_g'), $request->validated('making_per_g'), $request->validated('asking_price'), $request->certificate())])
            ->header('Cache-Control', 'public, max-age=30');
    }
}
