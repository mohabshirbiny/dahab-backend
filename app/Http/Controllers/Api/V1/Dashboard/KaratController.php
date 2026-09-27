<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Reference\CreateKaratAction;
use App\Actions\Reference\ToggleKaratAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Reference\StoreKaratRequest;
use App\Http\Resources\Reference\KaratResource;
use App\Models\Karat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Karats (spec 004 User Story 3). Viewing needs reference.view, the on/off
 * switch karats.toggle (Part 2 §10), adding karats.create. Audited.
 */
class KaratController extends Controller
{
    #[OA\Get(
        path: '/dashboard/karats',
        operationId: 'dashboardListKarats',
        summary: 'List karats in display order',
        description: 'Enabled and disabled. Requires reference.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        responses: [
            new OA\Response(response: 200, description: 'Karats', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardKarat')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return KaratResource::collection(Karat::query()->ordered()->get());
    }

    #[OA\Post(
        path: '/dashboard/karats',
        operationId: 'dashboardCreateKarat',
        summary: 'Add a karat (starts off)',
        description: 'Audited (reference.karat.created). Requires karats.create.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStoreKarat')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardKarat'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (duplicate code, out of range)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreKaratRequest $request, CreateKaratAction $create): JsonResponse
    {
        return KaratResource::make($create->handle($request->user('staff'), $request->validated()))
            ->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/dashboard/karats/{code}/toggle',
        operationId: 'dashboardToggleKarat',
        summary: 'Turn a karat on or off',
        description: 'Takes effect at once. Audited when it changes (reference.karat.toggled). Requires karats.toggle.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Reference Data'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 24))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['enabled'],
            properties: [new OA\Property(property: 'enabled', type: 'boolean')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Karat', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardKarat'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function toggle(Request $request, int $code, ToggleKaratAction $toggle): KaratResource
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        return KaratResource::make($toggle->handle($request->user('staff'), $code, (bool) $enabled));
    }
}
