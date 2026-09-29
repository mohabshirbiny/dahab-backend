<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\TopUp\SaveReceivingAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ReceivingAccount\StoreReceivingAccountRequest;
use App\Http\Requests\Dashboard\ReceivingAccount\UpdateReceivingAccountRequest;
use App\Http\Resources\Staff\ReceivingAccountResource;
use App\Models\ReceivingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Dahab's receiving accounts (spec 009 US4): the bank, InstaPay and Vodafone
 * Cash accounts customers send money to. Reading needs topup.match or
 * topup.accounts.manage (the match form picks an account); changing needs
 * topup.accounts.manage. Accounts are deactivated, never deleted.
 */
class ReceivingAccountController extends Controller
{
    #[OA\Get(
        path: '/dashboard/receiving-accounts',
        operationId: 'dashboardReceivingAccounts',
        summary: 'Dahab\'s receiving accounts',
        description: 'Spec 009 FR-002. Active accounts by method and sort order; include_inactive=1 adds the deactivated ones. Requires topup.match or topup.accounts.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Top-ups'],
        parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [
            new OA\Response(response: 200, description: 'The accounts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffReceivingAccount')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $accounts = ReceivingAccount::query()
            ->with('updatedBy')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->displayOrder()
            ->get();

        return response()->json(['data' => ReceivingAccountResource::collection($accounts)->resolve($request)]);
    }

    #[OA\Post(
        path: '/dashboard/receiving-accounts',
        operationId: 'dashboardStoreReceivingAccount',
        summary: 'Add a receiving account',
        description: 'Spec 009 FR-002/FR-003. Each method carries exactly its own details. Audited (receiving_account.created) with the full record. Requires topup.accounts.manage.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardStoreReceivingAccount')),
        tags: ['Dashboard Top-ups'],
        responses: [
            new OA\Response(response: 201, description: 'Added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffReceivingAccount')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(StoreReceivingAccountRequest $request, SaveReceivingAccountAction $save): JsonResponse
    {
        $account = $save->create($request->user('staff'), $request->topUpMethod(), $request->validated(), $request->attributes->get('context'));

        return ReceivingAccountResource::make($account->load('updatedBy'))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/dashboard/receiving-accounts/{account}',
        operationId: 'dashboardUpdateReceivingAccount',
        summary: 'Change or deactivate a receiving account',
        description: 'Spec 009 FR-002/FR-003. Any editable field; `method` is prohibited (it never changes). is_active=false hides it from customers immediately; past records keep pointing to it. Audited (receiving_account.updated) with before/after. Requires topup.accounts.manage.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardUpdateReceivingAccount')),
        tags: ['Dashboard Top-ups'],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffReceivingAccount')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function update(UpdateReceivingAccountRequest $request, int $account, SaveReceivingAccountAction $save): JsonResponse
    {
        $updated = $save->update($request->user('staff'), $account, $request->validated(), $request->attributes->get('context'));

        return ReceivingAccountResource::make($updated->load('updatedBy'))->response();
    }
}
