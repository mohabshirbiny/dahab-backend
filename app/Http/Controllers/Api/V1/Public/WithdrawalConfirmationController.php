<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Withdrawals\Public\ConfirmWithdrawalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ConfirmationTokenRequest;
use App\Models\WithdrawalConfirmation;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The email link's page (spec 013 FR-008, research R5): no token, the email
 * link's secret is the key. `read` has no side effect; `confirm` needs the
 * customer's tap. Throttled per IP (public.withdrawal_confirmations).
 */
#[OA\Schema(
    schema: 'PublicWithdrawalConfirmation',
    required: ['state', 'amount', 'account_masked', 'expires_at'],
    properties: [
        new OA\Property(property: 'state', type: 'string', enum: ['sent', 'confirmed', 'used', 'expired', 'replaced']),
        new OA\Property(property: 'amount', type: 'string', example: '42000.0000'),
        new OA\Property(property: 'account_masked', type: 'string', example: 'CIB •••• 4417'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    ],
)]
class WithdrawalConfirmationController extends Controller
{
    #[OA\Post(
        path: '/withdrawal-confirmations/read',
        operationId: 'publicReadWithdrawalConfirmation',
        summary: 'What this email link confirms',
        description: 'Spec 013 R5. No side effect. 422 confirmation_invalid for an unknown token.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WithdrawalConfirmationTokenRequest')),
        tags: ['Public'],
        responses: [
            new OA\Response(response: 200, description: 'The confirmation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicWithdrawalConfirmation')])),
            new OA\Response(response: 422, description: 'confirmation_invalid | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function read(ConfirmationTokenRequest $request, ConfirmWithdrawalAction $confirm): JsonResponse
    {
        return response()->json(['data' => $this->shape($confirm->read($request->token()))]);
    }

    #[OA\Post(
        path: '/withdrawal-confirmations/confirm',
        operationId: 'publicConfirmWithdrawal',
        summary: 'Confirm the withdrawal from the email link',
        description: 'Spec 013 FR-008 (Part 1 §2.4). Marks the confirmation confirmed; the customer then submits the withdrawal in the app. Confirming twice answers the same. 422 confirmation_invalid when unknown, expired, replaced or used. Audited.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WithdrawalConfirmationTokenRequest')),
        tags: ['Public'],
        responses: [
            new OA\Response(response: 200, description: 'Confirmed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PublicWithdrawalConfirmation')])),
            new OA\Response(response: 422, description: 'confirmation_invalid | validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function confirm(ConfirmationTokenRequest $request, ConfirmWithdrawalAction $confirm): JsonResponse
    {
        return response()->json(['data' => $this->shape($confirm->confirm($request->token(), $request->attributes->get('context')))]);
    }

    /** @return array<string, string> */
    private function shape(WithdrawalConfirmation $c): array
    {
        return [
            'state' => $c->state(),
            'amount' => bcadd((string) $c->amount, '0', 4),
            'account_masked' => (string) $c->account?->shortLabel(),
            'expires_at' => $c->expires_at->toIso8601String(),
        ];
    }
}
