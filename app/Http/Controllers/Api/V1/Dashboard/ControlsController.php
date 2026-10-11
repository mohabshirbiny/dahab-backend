<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Controls\ClearCategoryControlAction;
use App\Actions\Controls\SetCategoryControlAction;
use App\Actions\Controls\SetVisibilityAction;
use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceStaffPermission;
use App\Http\Requests\Dashboard\Controls\ClearCategoryControlRequest;
use App\Http\Requests\Dashboard\Controls\SetCategoryControlRequest;
use App\Http\Requests\Dashboard\Controls\SetVisibilityRequest;
use App\Support\Controls\ControlsSnapshot;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Switches (spec 019 US7): category controls and what customers can see. Each level has its
 * own permission, checked here because it depends on the body; every write is idempotent
 * and audited.
 */
class ControlsController extends Controller
{
    #[OA\Get(
        path: '/dashboard/category-controls',
        operationId: 'dashboardCategoryControls',
        summary: 'The switches and what each would touch',
        description: 'Spec 019 US7. Per category: the strongest active control with who set it and why, and the live, no-request and price-locked counts; whether everything is stopped; and the visibility flags. Requires any of the four switch permissions.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Switches'],
        responses: [
            new OA\Response(response: 200, description: 'The switches'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ControlsSnapshot $snapshot): JsonResponse
    {
        return response()->json(['data' => $snapshot->staff()]);
    }

    #[OA\Post(
        path: '/dashboard/category-controls',
        operationId: 'dashboardSetCategoryControl',
        summary: 'Stop new listings, pause a category, or stop everything',
        description: 'Spec 019 US7. category.stop_new, category.pause or platform.stop_everything by level. A reason is required. Pause refuses waiting requests for more time and tells the sellers in the category; stop everything writes one row per category and emails the founders. Audited (control.set). Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSetCategoryControl')),
        tags: ['Dashboard Switches'],
        responses: [
            new OA\Response(response: 201, description: 'Switched on'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'control_already_active', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | control_category_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function set(SetCategoryControlRequest $request, SetCategoryControlAction $set, EnforceStaffPermission $gate, ControlsSnapshot $snapshot): JsonResponse
    {
        $level = $request->level();
        $gate->handle($request, fn () => response()->noContent(), $level->permission()->value);

        $set->handle(
            $request->user('staff'),
            $level,
            $request->category(),
            (string) $request->input('reason'),
            $request->input('message_en'),
            $request->input('message_ar'),
            $request->attributes->get('context'),
        );

        return response()->json(['data' => $snapshot->staff()], 201);
    }

    #[OA\Post(
        path: '/dashboard/category-controls/clear',
        operationId: 'dashboardClearCategoryControl',
        summary: 'Switch a control off',
        description: 'Spec 019 US7. The same permission as the level. The row is kept with who cleared it and when. Audited (control.cleared). Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardClearCategoryControl')),
        tags: ['Dashboard Switches'],
        responses: [
            new OA\Response(response: 200, description: 'Switched off'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'control_not_active', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function clear(ClearCategoryControlRequest $request, ClearCategoryControlAction $clear, EnforceStaffPermission $gate, ControlsSnapshot $snapshot): JsonResponse
    {
        $level = $request->level();
        $gate->handle($request, fn () => response()->noContent(), $level->permission()->value);

        $clear->handle($request->user('staff'), $level, $request->category(), $request->attributes->get('context'));

        return response()->json(['data' => $snapshot->staff()]);
    }

    #[OA\Get(
        path: '/dashboard/visibility',
        operationId: 'dashboardVisibility',
        summary: 'What customers can see',
        description: 'Spec 019 US7. The four flags. Requires any of the four switch permissions.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Switches'],
        responses: [new OA\Response(response: 200, description: 'The flags')],
    )]
    public function visibility(ControlsSnapshot $snapshot): JsonResponse
    {
        return response()->json(['data' => $snapshot->visibility()]);
    }

    #[OA\Patch(
        path: '/dashboard/visibility/{key}',
        operationId: 'dashboardSetVisibility',
        summary: 'Show or hide one item',
        description: 'Spec 019 US7. visibility.manage. Kept in the setting history and audited (visibility.changed); pricing is not affected. Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSetVisibility')),
        tags: ['Dashboard Switches'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['rapaport_reference', 'payout_averages', 'listing_stats', 'sharing']))],
        responses: [
            new OA\Response(response: 200, description: 'Saved'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function setVisibility(SetVisibilityRequest $request, string $key, SetVisibilityAction $set, ControlsSnapshot $snapshot): JsonResponse
    {
        $settingKey = SettingKey::tryFrom('visibility.'.$key);
        abort_if($settingKey === null || $settingKey->group() !== SettingGroup::VISIBILITY, 404);

        $set->handle($request->user('staff'), $settingKey, $request->boolean('shown'), $request->attributes->get('context'));

        return response()->json(['data' => $snapshot->visibility()]);
    }
}
