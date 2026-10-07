<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Account\CloseAccountAction;
use App\Enums\CloseReason;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Close my account (spec 017 US7, FR-050, FR-051). Every signed-in customer;
 * refused while anything is in progress or money is left, with the list.
 */
#[OA\Schema(
    schema: 'CloseBlocker',
    required: ['code', 'count'],
    properties: [
        new OA\Property(property: 'code', type: 'string', enum: ['open_order', 'active_buy_request', 'listing_in_sale', 'piece_at_branch', 'wallet_balance', 'pending_withdrawal', 'open_dispute', 'pending_extension_request', 'pending_topup']),
        new OA\Property(property: 'count', type: 'integer'),
    ],
)]
class CloseAccountController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/account/close-check',
        operationId: 'customerCloseCheck',
        summary: 'What stops the account from closing now',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        responses: [new OA\Response(response: 200, description: 'Blockers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
            new OA\Property(property: 'can_close', type: 'boolean'),
            new OA\Property(property: 'blockers', type: 'array', items: new OA\Items(ref: '#/components/schemas/CloseBlocker')),
        ], type: 'object')]))],
    )]
    public function check(Request $request, CloseAccountAction $close): JsonResponse
    {
        $blockers = $close->check($this->customer($request));

        return ApiResponse::ok(['can_close' => $blockers === [], 'blockers' => $blockers]);
    }

    #[OA\Post(
        path: '/customer/me/account/close',
        operationId: 'customerCloseAccount',
        summary: 'Close the account',
        description: 'Spec 017 FR-050, FR-051. Pieces not in a sale leave the market; every session ends; sign-in is refused afterwards with account_closed. Nothing is deleted. `note` only with reason other.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', enum: ['finished', 'fees_too_high', 'too_slow_to_sell', 'data_trust', 'something_went_wrong', 'other']),
            new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
        ])),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'account_has_open_items (details.blockers)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function close(Request $request, CloseAccountAction $close): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::enum(CloseReason::class)],
            'note' => ['nullable', 'string', 'max:500', Rule::prohibitedIf($request->input('reason') !== CloseReason::OTHER->value)],
        ]);
        $customer = $close->close($this->customer($request), CloseReason::from($data['reason']), $data['note'] ?? null, $request->attributes->get('context'));

        return ApiResponse::ok(['closed_at' => $customer->closed_at?->toIso8601String()]);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
