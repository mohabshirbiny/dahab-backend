<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\TopUp\CancelTopUpNoticeAction;
use App\Actions\TopUp\ListCustomerTopUpsAction;
use App\Actions\TopUp\ListTopUpMethodsAction;
use App\Actions\TopUp\SubmitTopUpNoticeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\TopUp\ListCustomerTopUpsRequest;
use App\Http\Requests\Customer\TopUp\SubmitTopUpNoticeRequest;
use App\Http\Resources\Customer\ReceivingAccountResource;
use App\Http\Resources\Customer\TopUpResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Wallet top-up, customer side (spec 009 US1): a manual transfer to one of
 * Dahab's receiving accounts, then a notice. No endpoint here moves money;
 * staff credit what arrives (Dashboard Incoming transfers).
 */
class TopUpController extends Controller
{
    #[OA\Get(
        path: '/customer/me/wallet/topup-methods',
        operationId: 'customerTopUpMethods',
        summary: 'Where to send money, and the customer\'s reference',
        description: 'Spec 009 FR-004/FR-005. Dahab\'s active receiving accounts grouped by method (bank_transfer, instapay, vodafone_cash) in display order, and the reference DAHAB-<display_ref> to quote in the transfer. A method with no active account is left out. Verified, non-suspended customers only (trade gate): a suspended customer is refused the receiving details.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        responses: [
            new OA\Response(response: 200, description: 'Methods and reference', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'reference', type: 'string', example: 'DAHAB-004417'),
                    new OA\Property(property: 'methods', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash']),
                        new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerReceivingAccount')),
                    ], type: 'object')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function methods(Request $request, ListTopUpMethodsAction $methods): JsonResponse
    {
        $result = $methods->handle($request->user('customer'));

        return response()->json(['data' => [
            'reference' => $result['reference'],
            'methods' => array_map(fn (array $m) => [
                'method' => $m['method'],
                'accounts' => ReceivingAccountResource::collection($m['accounts'])->resolve($request),
            ], $result['methods']),
        ]]);
    }

    #[OA\Post(
        path: '/customer/me/wallet/topups',
        operationId: 'customerSubmitTopUpNotice',
        summary: 'I\'ve sent the transfer',
        description: 'Spec 009 FR-007–FR-011. Records a pending transfer notice; no money moves until staff see it arrive and credit what actually arrived. Verified, non-suspended customers only (trade gate). Idempotent: requires an Idempotency-Key header. Throttled per customer (customer.topups).',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubmitTopUpNoticeRequest')),
        tags: ['Customer Wallet'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'The pending notice', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerTopUp')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (amount, inactive account) | upload_token_invalid | idempotency_key_mismatch', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(SubmitTopUpNoticeRequest $request, SubmitTopUpNoticeAction $submit): JsonResponse
    {
        $topUp = $submit->handle(
            $request->user('customer'),
            $request->amount(),
            (int) $request->validated('receiving_account_id'),
            $request->validated('receipt_upload_token'),
        );

        return TopUpResource::make($topUp)->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/customer/me/wallet/topups',
        operationId: 'customerTopUps',
        summary: 'The customer\'s own top-ups',
        description: 'Spec 009 FR-012. Notices and hand credits, newest first, keyset-paginated. Verified customers; a suspended customer may read. Never includes staff notes or receiving details.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'on_hold', 'credited', 'rejected', 'cancelled'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of top-ups', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerTopUp')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListCustomerTopUpsRequest $request, ListCustomerTopUpsAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->user('customer')->customer_id, $request->status(), $request->cursor(), $perPage);

        return response()->json([
            'data' => TopUpResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Post(
        path: '/customer/me/wallet/topups/{topup}/cancel',
        operationId: 'customerCancelTopUpNotice',
        summary: 'Withdraw a pending notice',
        description: 'Spec 009 FR-025b. pending → cancelled; no money moves, and a cancelled notice is never credited. Verified customers; a suspended customer may cancel. Idempotent: requires an Idempotency-Key header.',
        security: [['customerBearer' => []]],
        tags: ['Customer Wallet'],
        parameters: [
            new OA\Parameter(name: 'topup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cancelled notice', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerTopUp')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found (none, or not the customer\'s)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'illegal_topup_transition (not pending) | idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function cancel(Request $request, string $topup, CancelTopUpNoticeAction $cancel): JsonResponse
    {
        return TopUpResource::make($cancel->handle($request->user('customer'), $topup))->response();
    }
}
