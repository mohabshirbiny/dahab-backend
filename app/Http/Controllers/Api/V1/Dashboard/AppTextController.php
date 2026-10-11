<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Content\CreateFaqEntryAction;
use App\Actions\Content\DiscardDraftAction;
use App\Actions\Content\ExportAppTextsAction;
use App\Actions\Content\ListAppTextsAction;
use App\Actions\Content\PublishTextsAction;
use App\Actions\Content\ReorderFaqAction;
use App\Actions\Content\RevertTextAction;
use App\Actions\Content\SaveDraftAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Content\CreateFaqEntryRequest;
use App\Http\Requests\Dashboard\Content\ListAppTextsRequest;
use App\Http\Requests\Dashboard\Content\MoveFaqEntryRequest;
use App\Http\Requests\Dashboard\Content\RevertAppTextRequest;
use App\Http\Requests\Dashboard\Content\SaveAppTextDraftRequest;
use App\Http\Resources\Staff\AppTextResource;
use App\Http\Resources\Staff\AppTextVersionResource;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Support\Content\SettingPlaceholders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * App text (spec 019 US1, `content.edit`): the registry of the customer app's texts, drafts,
 * *Publish changes*, revert to the code default, history and export. Every write is idempotent
 * and audited with the version it replaced; a draft is invisible to customers until published.
 */
class AppTextController extends Controller
{
    private const KEY_PATTERN = '[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,5}';

    #[OA\Get(
        path: '/dashboard/app-texts',
        operationId: 'dashboardAppTexts',
        summary: 'The customer app\'s texts',
        description: 'Spec 019 US1. Every registered key with where it appears, the code default, what customers see now, the pending draft, and whether the code default changed since the live override. Filters: kind, area, q, changed (live override or not), state (draft | published | default). Keyset-paginated by key. Requires content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['app', 'faq', 'notification'])),
            new OA\Parameter(name: 'area', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'changed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'published', 'default'])),
            new OA\Parameter(name: 'include_retired', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of texts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffAppText')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListAppTextsRequest $request, ListAppTextsAction $list): JsonResponse
    {
        $page = $list->handle($request->filters(), $request->afterKey(), $request->perPage());

        return response()->json([
            'data' => AppTextResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $request->perPage(), 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/app-texts/export',
        operationId: 'dashboardAppTextsExport',
        summary: 'The texts as CSV',
        description: 'Spec 019 FR-011. Key, kind, area, English, Arabic, version and published date of what customers see, under the same filters as the list. UTF-8 with a BOM. Every export is audited (content.exported). Requires content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function export(ListAppTextsRequest $request, ExportAppTextsAction $export): Response
    {
        $result = $export->handle($request->user('staff'), $request->filters());

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="app-texts-'.now()->format('Y-m-d').'.csv"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    #[OA\Put(
        path: '/dashboard/app-texts/{key}/draft',
        operationId: 'dashboardSaveAppTextDraft',
        summary: 'Save a text as a draft',
        description: 'Spec 019 US1, Q2. One shared draft per key; invisible to customers until Publish changes. Both languages required and within the key\'s length; only the key\'s own placeholders and the setting placeholders; a required placeholder ({code}, {link}) must stay. Refused when it equals what customers see now. Audited (content.draft_saved) with the version it replaces. Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSaveAppTextDraft')),
        tags: ['Dashboard App text'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'order.free_relist.card_title'))],
        responses: [
            new OA\Response(response: 200, description: 'The draft', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffAppTextDraft')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'content_draft_changed | content_key_read_only | content_key_retired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | content_language_missing | content_too_long | content_invalid_placeholder | content_missing_required_placeholder | content_unchanged', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function saveDraft(SaveAppTextDraftRequest $request, string $key, SaveDraftAction $save): JsonResponse
    {
        $draft = $save->handle(
            $request->user('staff'),
            $key,
            (string) $request->validated('text_en'),
            (string) $request->validated('text_ar'),
            $request->validated('draft_version') === null ? null : (int) $request->validated('draft_version'),
            $request->has('is_hidden') ? $request->boolean('is_hidden') : null,
            $request->attributes->get('context'),
        );

        return response()->json(['data' => ['key' => $key] + AppTextResource::draft($draft->load('editor'))]);
    }

    #[OA\Delete(
        path: '/dashboard/app-texts/{key}/draft',
        operationId: 'dashboardDiscardAppTextDraft',
        summary: 'Discard a text\'s draft',
        description: 'Spec 019 US1. Deletes the pending draft; customers never saw it. Audited (content.draft_discarded) with what it said. Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Discarded'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found | content_no_draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function discardDraft(Request $request, string $key, DiscardDraftAction $discard): Response
    {
        $discard->handle($request->user('staff'), $key, $request->attributes->get('context'));

        return response()->noContent();
    }

    #[OA\Post(
        path: '/dashboard/app-texts/{key}/revert',
        operationId: 'dashboardRevertAppText',
        summary: 'Put a text back',
        description: 'Spec 019 FR-002. `to: "default"` makes a default-marker draft: once published the override ends, the bundle drops the key and the app shows its own current default. `to: <version>` makes a draft copying that version. Both are drafts until Publish changes. Audited (content.reverted). Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardRevertAppText')),
        tags: ['Dashboard App text'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The draft', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffAppTextDraft')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'content_key_read_only | content_key_retired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | content_unchanged | a content placeholder rule', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function revert(RevertAppTextRequest $request, string $key, RevertTextAction $revert): JsonResponse
    {
        $draft = $revert->handle($request->user('staff'), $key, $request->target(), $request->attributes->get('context'));

        return response()->json(['data' => ['key' => $key] + AppTextResource::draft($draft->load('editor'))]);
    }

    #[OA\Post(
        path: '/dashboard/app-texts/publish',
        operationId: 'dashboardPublishAppTexts',
        summary: 'Publish changes',
        description: 'Spec 019 Q2. Every pending draft goes live together as one new bundle with the publisher and the time, or none does. Each draft is validated again; a failure lists every key with its reason (content_invalid) and nothing is published. Two publishes at once give two consecutive bundles. Audited (content.published) with, per key, the version replaced and the wording before and after. Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        responses: [
            new OA\Response(response: 201, description: 'Published', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'bundle_version', type: 'integer'),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'keys', type: 'array', items: new OA\Items(type: 'string')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'content_nothing_to_publish | content_invalid (details.keys)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function publish(Request $request, PublishTextsAction $publish): JsonResponse
    {
        return response()->json(['data' => $publish->handle($request->user('staff'), $request->attributes->get('context'))], 201);
    }

    #[OA\Get(
        path: '/dashboard/app-texts/{key}/history',
        operationId: 'dashboardAppTextHistory',
        summary: 'Every version of a text',
        description: 'Spec 019 FR-002. Newest first: the draft (if any), the live version and every earlier one, with who wrote and published each and when. Requires content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The versions', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffAppTextVersion')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function history(Request $request, string $key): JsonResponse
    {
        $text = AppTextKey::query()->whereKey($key)->firstOrFail();
        $versions = AppTextVersion::query()->where('text_key', $text->text_key)->with(['editor', 'publisher'])->orderByDesc('version')->get();

        return response()->json(['data' => AppTextVersionResource::collection($versions)->resolve($request)]);
    }

    #[OA\Get(
        path: '/dashboard/content/placeholders',
        operationId: 'dashboardContentPlaceholders',
        summary: 'The setting placeholders and their live values',
        description: 'Spec 019 R5. The {names} a text may use for a number a setting owns, with the value customers will see. Requires content.edit.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard App text'],
        responses: [
            new OA\Response(response: 200, description: 'Placeholders', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'commission_gold_pct'),
                    new OA\Property(property: 'setting', type: 'string', example: 'commission.gold_pct'),
                    new OA\Property(property: 'value', type: 'string', nullable: true, example: '20'),
                ], type: 'object')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function placeholders(SettingPlaceholders $placeholders): JsonResponse
    {
        $values = $placeholders->values();

        return response()->json(['data' => array_map(
            fn (string $name, $setting) => ['name' => $name, 'setting' => $setting->value, 'value' => $values[$name] ?? null],
            array_keys(SettingPlaceholders::MAP),
            array_values(SettingPlaceholders::MAP),
        )]);
    }

    #[OA\Post(
        path: '/dashboard/faq',
        operationId: 'dashboardCreateFaqEntry',
        summary: 'Add a Help entry',
        description: 'Spec 019 FR-030. Creates the two keys help.faq.<slug>.question and .answer, each with a draft in both languages, at the end of the list. Customers see it only after Publish changes; is_hidden keeps it out of the app. Audited (content.faq_created). Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardCreateFaqEntry')),
        tags: ['Dashboard App text'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'slug', type: 'string'),
                new OA\Property(property: 'position', type: 'integer'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'content_faq_exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | content_language_missing | content_too_long | a content placeholder rule', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function createFaq(CreateFaqEntryRequest $request, CreateFaqEntryAction $create): JsonResponse
    {
        $result = $create->handle($request->user('staff'), (string) $request->input('slug'), $request->texts(), $request->attributes->get('context'));

        return response()->json(['data' => $result], 201);
    }

    #[OA\Patch(
        path: '/dashboard/faq/{slug}/position',
        operationId: 'dashboardMoveFaqEntry',
        summary: 'Move a Help entry',
        description: 'Spec 019 FR-031. Moves the entry to a position (1 = first). The new order is a draft (pending_position on the entry\'s keys) and goes live with Publish changes. Audited (content.faq_reordered). Requires content.edit and an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardMoveFaqEntry')),
        tags: ['Dashboard App text'],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Moved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'slug', type: 'string'),
                new OA\Property(property: 'position', type: 'integer'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'content_faq_unknown', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | content_unchanged', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function moveFaq(MoveFaqEntryRequest $request, string $slug, ReorderFaqAction $reorder): JsonResponse
    {
        $result = $reorder->handle($request->user('staff'), $slug, (int) $request->input('position'), $request->attributes->get('context'));

        return response()->json(['data' => $result]);
    }

    public static function keyPattern(): string
    {
        return self::KEY_PATTERN;
    }
}
