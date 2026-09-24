<?php

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Actions\Auth\Customer\CustomerLoginChallengeAction;
use App\Actions\Auth\Customer\VerifyCustomerLoginOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Customer\ResendLoginOtpRequest;
use App\Http\Requests\Auth\Customer\VerifyLoginOtpRequest;
use App\Http\Resources\Customer\CustomerResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * The second half of a sign-in from a new device (Part 1 §2.3). Login
 * answers `otp_required` with a `challenge_id`; these endpoints resend the
 * SMS code and, on a correct code, trust the device and issue the session.
 *
 * Both must be called with the same `X-Device-Id` / `X-Device-Platform` the
 * login used — a challenge is bound to the device that opened it.
 */
#[OA\Schema(
    schema: 'CustomerOtpChallenge',
    description: 'A sign-in held for a new device. Send the SMS code to /customer/auth/otp/verify.',
    required: ['otp_required', 'otp_channel', 'challenge_id', 'expires_at', 'resend_available_at'],
    properties: [
        new OA\Property(property: 'otp_required', type: 'boolean', example: true),
        new OA\Property(property: 'otp_channel', type: 'string', enum: ['sms']),
        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', description: 'When the current code stops working'),
        new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
    ],
)]
class CustomerLoginOtpController extends Controller
{
    public function __construct(
        private readonly VerifyCustomerLoginOtpAction $verify,
        private readonly CustomerLoginChallengeAction $challenge,
    ) {}

    #[OA\Post(
        path: '/customer/auth/otp/verify',
        operationId: 'customerAuthOtpVerify',
        summary: 'Verify the SMS code for a new-device sign-in; trusts the device and issues the session',
        tags: ['Customer Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Device-Platform', in: 'header', required: false, schema: new OA\Schema(type: 'string', default: 'web')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VerifyLoginOtpRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Device trusted; session issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'customer', ref: '#/components/schemas/CustomerProfile'),
                    new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'otp_invalid (wrong code, voided/unknown challenge, other device) or otp_expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'account_pending_verification, account_rejected or account_suspended (status changed while the challenge was open)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function verify(VerifyLoginOtpRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->verify->execute($request->validated('challenge_id'), $request->validated('code'), $ctx);

        return response()->json([
            'data' => [
                'customer' => (new CustomerResource($result['customer']))->toArray($request),
                'session' => $result['session']->toArray(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/otp/resend',
        operationId: 'customerAuthOtpResend',
        summary: 'Send a fresh SMS code for a pending new-device sign-in',
        description: 'Allowed once per `otp.send_cooldown_seconds` and at most `otp.max_sends_per_hour` codes per challenge.',
        tags: ['Customer Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Device-Platform', in: 'header', required: false, schema: new OA\Schema(type: 'string', default: 'web')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ResendLoginOtpRequest')),
        responses: [
            new OA\Response(response: 200, description: 'New code sent', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOtpChallenge'),
            ])),
            new OA\Response(response: 401, description: 'otp_invalid — unknown/voided challenge or another device', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — cooldown (Retry-After set) or hourly cap', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function resend(ResendLoginOtpRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return response()->json([
            'data' => self::challengePayload($this->challenge->resend($request->validated('challenge_id'), $ctx)),
        ]);
    }

    /**
     * @param  array{challenge_id: string, expires_at: Carbon, resend_available_at: Carbon}  $challenge
     * @return array<string, mixed>
     */
    public static function challengePayload(array $challenge): array
    {
        return [
            'otp_required' => true,
            'otp_channel' => 'sms',
            'challenge_id' => $challenge['challenge_id'],
            'expires_at' => $challenge['expires_at']->toIso8601String(),
            'resend_available_at' => $challenge['resend_available_at']->toIso8601String(),
        ];
    }
}
