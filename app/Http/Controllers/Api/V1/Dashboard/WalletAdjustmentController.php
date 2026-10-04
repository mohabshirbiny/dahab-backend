<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Finance\AdjustWalletAction;
use App\Actions\Finance\ListWalletAdjustmentsAction;
use App\Enums\AdjustmentDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Finance\AdjustWalletRequest;
use App\Http\Resources\Staff\WalletAdjustmentResource;
use App\Models\Customer;
use App\Support\Finance\Period;
use App\Support\Listings\ListingCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Adjust a wallet balance directly (spec 015 US2; Part 1 §4.2 "CEO only"):
 * wallet.adjust, seeded to no role — the founders hold every code. Reads with
 * wallet.adjust or wallet.view. The POST is idempotent and audited.
 */
class WalletAdjustmentController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/customers/{customer}/wallet-adjustments',
        operationId: 'dashboardWalletAdjust',
        summary: 'Credit or debit a customer\'s wallet',
        description: 'Spec 015 FR-006/FR-007. One balanced entry of kind reversal with no reversed entry: the customer\'s available ± amount against external_equity ∓ amount; a wallet_adjustment row; a debit never takes available below zero (insufficient_funds, details.available and shortfall). The customer is told by SMS + email, without the reason, and sees the line as a Correction. wallet.adjust. Idempotent, audited (wallet.adjusted).',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AdjustWalletRequest')),
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Adjusted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWalletAdjustment')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'insufficient_funds', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(AdjustWalletRequest $request, string $customer, AdjustWalletAction $adjust): JsonResponse
    {
        $id = Customer::query()->whereKey($customer)->valueOrFail('customer_id');
        $adjustment = $adjust->handle($request->user('staff'), $id, AdjustmentDirection::from($request->validated('direction')),
            $request->validated('amount'), $request->validated('reason'), $request->attributes->get('context'));

        return response()->json(['data' => (new WalletAdjustmentResource($adjustment->load(ListWalletAdjustmentsAction::RELATIONS)))->resolve($request)], 201);
    }

    #[OA\Get(
        path: '/dashboard/wallet-adjustments',
        operationId: 'dashboardWalletAdjustments',
        summary: 'Wallet adjustments',
        description: 'Spec 015 FR-006. Newest first, keyset pages; from/to (Cairo dates, default the last 30 days), customer_id. wallet.adjust or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffWalletAdjustment')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, ListWalletAdjustmentsAction $list): JsonResponse
    {
        $request->validate(Period::rules() + [
            'customer_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        [$from, $to] = Period::of($request, 30);
        $perPage = (int) $request->input('per_page', 25);
        $page = $list->handle($from, $to, $request->input('customer_id'), ListingCursor::decode($request->input('cursor')), $perPage);

        return response()->json([
            'data' => WalletAdjustmentResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }
}
