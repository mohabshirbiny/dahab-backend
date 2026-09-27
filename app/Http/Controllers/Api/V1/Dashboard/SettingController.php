<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Pricing\ChangeSettingAction;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceStaffPermission;
use App\Http\Requests\Dashboard\Pricing\UpdateSettingRequest;
use App\Http\Resources\Pricing\SettingChangeResource;
use App\Http\Resources\Pricing\SettingResource;
use App\Models\Setting;
use App\Models\SettingHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Settings (spec 005 US3/US5): every tunable number, changed with a reason by
 * the key's group permission, with its history.
 */
class SettingController extends Controller
{
    #[OA\Get(
        path: '/dashboard/settings',
        operationId: 'dashboardListSettings',
        summary: 'List every setting with its group, range and last change',
        description: 'Ordered by group, then key. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardSetting')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $settings = Setting::query()->with('updatedBy')->get()
            ->sortBy(fn (Setting $s) => SettingKey::from($s->setting_key)->group()->value.'|'.$s->setting_key)
            ->values();

        return SettingResource::collection($settings);
    }

    #[OA\Patch(
        path: '/dashboard/settings/{key}',
        operationId: 'dashboardUpdateSetting',
        summary: 'Change a setting',
        description: 'A reason is required. Rates keys need pricing.rates.manage; operations keys need settings.manage. Type and range per the catalogue. Kept in history and audited (pricing.setting.changed). Applies to the next calculation.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'commission.gold_pct'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateSetting')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardSetting'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | reason_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateSettingRequest $request, string $key, ChangeSettingAction $change, EnforceStaffPermission $gate): SettingResource
    {
        $settingKey = SettingKey::tryFrom($key) ?? abort(404);

        // The permission depends on the key's group, so it is checked here, the same way the route middleware does.
        $gate->handle($request, fn () => response()->noContent(), $settingKey->permission()->value);

        return SettingResource::make($change->handle($request->user('staff'), $settingKey, $request->input('value'), $request->validated('reason')));
    }

    #[OA\Get(
        path: '/dashboard/settings/history',
        operationId: 'dashboardSettingHistory',
        summary: 'Setting changes, newest first',
        description: 'Optionally for one key. Requires pricing.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Pricing'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Changes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardSettingChange')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function history(Request $request): AnonymousResourceCollection
    {
        $query = $request->validate([
            'key' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        return SettingChangeResource::collection(
            SettingHistory::query()->with('changedBy')
                ->when($query['key'] ?? null, fn ($q, $key) => $q->where('setting_key', $key))
                ->orderByDesc('changed_at')->orderByDesc('setting_history_id')
                ->paginate($query['per_page'] ?? 25),
        );
    }
}
