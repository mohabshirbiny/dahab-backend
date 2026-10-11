<?php

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Actions\Auth\Customer\ConfirmPasswordResetAction;
use App\Actions\Auth\Customer\RequestPasswordResetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Customer\PasswordResetConfirmRequest;
use App\Http\Requests\Auth\Customer\PasswordResetRequestRequest;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * "I forgot my password" for a customer (spec 020 US1): an SMS code to the phone on file,
 * then a new password. Public routes; the answers never reveal whether a number is registered.
 */
class CustomerPasswordResetController extends Controller
{
    public function __construct(
        private readonly RequestPasswordResetAction $request,
        private readonly ConfirmPasswordResetAction $confirm,
    ) {}

    #[OA\Post(
        path: '/customer/auth/password/reset-request',
        operationId: 'customerAuthPasswordResetRequest',
        summary: 'Ask for a code to reset a forgotten password',
        description: 'Sends a six-digit SMS code to the phone when it belongs to an account that is not closed. The answer is identical for a registered, unregistered or closed number, and while a code was sent a moment ago. Limited per phone (3 an hour), per device (10) and per IP (20).',
        tags: ['Customer Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PasswordResetRequestRequest')),
        responses: [
            new OA\Response(response: 202, description: 'Accepted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'expires_in_seconds', type: 'integer', example: 300),
                    new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'validation_failed — phone missing or not E.164', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function requestReset(PasswordResetRequestRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->request->execute($request->validated('phone'), $ctx);

        return response()->json(['data' => [
            'expires_in_seconds' => $result['expires_in_seconds'],
            'resend_available_at' => $result['resend_available_at']->toIso8601String(),
        ]], 202);
    }

    #[OA\Post(
        path: '/customer/auth/password/reset-confirm',
        operationId: 'customerAuthPasswordResetConfirm',
        summary: 'Set a new password with the SMS code',
        description: 'Spends the code and changes the password. Every session ends and every trusted device is forgotten; withdrawals not yet released are cancelled and new ones pause (the payout safety stop). No session is issued: the customer signs in with the new password. Allowed for active, pending, rejected and suspended accounts; a closed account answers `otp_invalid` like an unknown number.',
        tags: ['Customer Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PasswordResetConfirmRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Password changed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'password_changed', type: 'boolean', example: true),
                    new OA\Property(property: 'signed_out_sessions', type: 'integer'),
                    new OA\Property(property: 'withdrawals_paused_until', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'cancelled_withdrawals', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'otp_invalid (wrong, unknown, voided, used, or no usable account) or otp_expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed — code not six digits, password breaks the rule or differs from its confirmation', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function confirmReset(PasswordResetConfirmRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->confirm->execute(
            $request->validated('phone'),
            $request->validated('code'),
            $request->validated('password'),
            $ctx,
        );

        return response()->json(['data' => [
            'password_changed' => true,
            'signed_out_sessions' => $result['signed_out_sessions'],
            'withdrawals_paused_until' => $result['withdrawals_paused_until']?->toIso8601String(),
            'cancelled_withdrawals' => $result['cancelled_withdrawals'],
        ]]);
    }
}
