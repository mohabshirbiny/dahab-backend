<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Wallet\ListCustomerWalletHistoryAction;
use App\Actions\Wallet\ListHeldItemsAction;
use App\Actions\Wallet\ShowCustomerWalletAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\WalletHistoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerWallet',
    required: ['available', 'held', 'total', 'currency'],
    properties: [
        new OA\Property(property: 'available', type: 'string', example: '600.0000', description: 'Spendable and withdrawable, EGP, 4 places'),
        new OA\Property(property: 'held', type: 'string', example: '400.0000', description: 'Set aside against open orders and withdrawals not yet sent; still the customer\'s'),
        new OA\Property(property: 'held_on_orders', type: 'string', example: '300.0000', description: 'Spec 013: the part of held set aside against open orders (held − pending_withdrawals)'),
        new OA\Property(property: 'pending_withdrawals', type: 'string', example: '100.0000', description: 'Spec 013: the part of held on its way to the customer\'s bank (withdrawals requested or under review)'),
        new OA\Property(property: 'total', type: 'string', example: '1000.0000'),
        new OA\Property(property: 'currency', type: 'string', example: 'EGP'),
    ],
)]
#[OA\Schema(
    schema: 'CustomerWalletMovement',
    required: ['id', 'kind', 'created_at', 'available_change', 'held_change', 'available_after', 'held_after', 'reference'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'kind', type: 'string', enum: ['topup', 'deposit_hold', 'deposit_release', 'deposit_forfeit', 'settlement_seller', 'first_sale_payout', 'commission', 'spread', 'vat', 'balance_payment', 'withdrawal', 'compensation', 'external_bank_movement', 'weight_adjustment', 'reversal'], description: 'The app shows its own en/ar wording for each kind'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'available_change', type: 'string', example: '-400.0000', description: 'A hold is money out of available'),
        new OA\Property(property: 'held_change', type: 'string', example: '400.0000'),
        new OA\Property(property: 'available_after', type: 'string', example: '600.0000'),
        new OA\Property(property: 'held_after', type: 'string', example: '400.0000'),
        new OA\Property(property: 'reference', type: 'string', nullable: true, description: 'The related top-up (TOP-{n}) or withdrawal (WD-{n}, spec 013) number; null otherwise'),
    ],
)]
class WalletController extends Controller
{
    #[OA\Get(
        path: '/customer/me/wallet',
        operationId: 'customerWallet',
        summary: 'The customer\'s own wallet',
        description: 'Spec 008 FR-013 / Part 2 §8. Available, held and total, derived from the ledger (never stored). Verified customers only; a suspended customer may still read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        responses: [
            new OA\Response(response: 200, description: 'The wallet', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerWallet')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, ShowCustomerWalletAction $wallet): JsonResponse
    {
        return response()->json(['data' => $wallet->handle($request->user('customer')->customer_id)]);
    }

    #[OA\Get(
        path: '/customer/me/wallet/held',
        operationId: 'customerWalletHeld',
        summary: 'What each buy request and order holds',
        description: 'Spec 015 FR-019. The customer\'s own requests and orders that hold money now, from the ledger (the held postings of each request), newest first; an accepted request appears as its order. total = the wallet\'s held_on_orders. Verified customers; a suspended customer may still read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        responses: [
            new OA\Response(response: 200, description: 'The held lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'total', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['buy_request', 'order']),
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'ref', type: 'string', nullable: true, description: 'The order reference; null for a request'),
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'title_ar', type: 'string', nullable: true),
                    new OA\Property(property: 'state', type: 'string'),
                    new OA\Property(property: 'amount', type: 'string'),
                ], type: 'object')),
            ], type: 'object')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function held(Request $request, ListHeldItemsAction $held): JsonResponse
    {
        return response()->json(['data' => $held->handle($request->user('customer')->customer_id)]);
    }

    #[OA\Get(
        path: '/customer/me/wallet/transactions',
        operationId: 'customerWalletTransactions',
        summary: 'The customer\'s own wallet history',
        description: 'Spec 008 FR-013. One row per ledger entry that touched the customer\'s accounts, newest first, keyset-paginated. The running balance is available: a hold is money out of available (held_change shows where it went). Never includes staff memos.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        parameters: [
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of movements', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerWalletMovement')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (e.g. a malformed cursor)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function transactions(WalletHistoryRequest $request, ListCustomerWalletHistoryAction $history): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $history->handle($request->user('customer')->customer_id, $request->cursor(), $perPage);

        return response()->json([
            'data' => $page['rows'],
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }
}
