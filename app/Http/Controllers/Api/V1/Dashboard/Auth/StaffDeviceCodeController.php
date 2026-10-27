<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Staff\StaffDeviceCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\DeviceCodeResendRequest;
use App\Http\Requests\Auth\Staff\DeviceCodeVerifyRequest;
use App\Http\Resources\Staff\StaffResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffDeviceCodePending',
    description: 'Password (and MFA) accepted, but this device is not yet known for the staff member and the setting auth.staff_new_device_sms is on: a code was sent by SMS to the staff phone. No token is issued. Send it to `POST /dashboard/auth/device-code/verify` with the same `X-Device-Id`. A founder gets this for any new device while the setting is on; with it off a founder waits for the other founder (StaffDeviceApprovalPending).',
    required: ['device_code_required', 'challenge_id', 'phone_hint', 'expires_at', 'resend_available_at'],
    properties: [
        new OA\Property(property: 'device_code_required', type: 'boolean', enum: [true]),
        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'phone_hint', type: 'string', example: '••• 123', description: 'The last three digits of the staff phone'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', description: 'When this code expires'),
        new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
    ],
)]
class StaffDeviceCodeController extends Controller
{
    public function __construct(private readonly StaffDeviceCodeAction $codes) {}

    #[OA\Post(
        path: '/dashboard/auth/device-code/verify',
        operationId: 'dashboardAuthDeviceCodeVerify',
        summary: 'Confirm a new staff device with the SMS code and sign in',
        description: 'The device becomes known and the session is issued. A founder\'s device confirmed this way alerts the founders (founder.device_trusted_by_sms). Send the same X-Device-Id as at sign-in.',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffDeviceCodeVerifyRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Session issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'staff', ref: '#/components/schemas/StaffProfile'),
                    new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'otp_invalid (wrong code, 5 wrong codes void the challenge, unknown or used challenge, another device, account no longer available) | otp_expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — per challenge and per IP', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function verify(DeviceCodeVerifyRequest $request): JsonResponse
    {
        $result = $this->codes->verify($request->validated('challenge_id'), $request->validated('code'), $request->attributes->get('context'));

        return response()->json(['data' => [
            'staff' => (new StaffResource($result['staff']))->toArray($request),
            'session' => $result['session']->toArray(),
        ]]);
    }

    #[OA\Post(
        path: '/dashboard/auth/device-code/resend',
        operationId: 'dashboardAuthDeviceCodeResend',
        summary: 'Send a fresh SMS code for a new staff device',
        description: 'After the resend cooldown, at most otp.max_sends_per_hour codes per challenge. The new code replaces the old one and the tries start again.',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffDeviceCodeResendRequest')),
        responses: [
            new OA\Response(response: 200, description: 'A new code was sent', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffDeviceCodePending'),
            ])),
            new OA\Response(response: 401, description: 'otp_invalid — unknown or used challenge, another device', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — too soon (details.resend_available_at, Retry-After) or too many codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function resend(DeviceCodeResendRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->codes->resend($request->validated('challenge_id'), $request->attributes->get('context'))]);
    }
}
