<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Staff\EnrollStaffMfaAction;
use App\Actions\Auth\Staff\VerifyStaffMfaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\MfaEnrollRequest;
use App\Http\Requests\Auth\Staff\MfaVerifyRequest;
use App\Http\Resources\Staff\StaffResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffMfaChallenge',
    description: 'Password accepted; the account has TOTP enrolled. Redeem `session_ref` at `POST /dashboard/auth/mfa/verify`.',
    required: ['mfa_required', 'session_ref', 'expires_at'],
    properties: [
        new OA\Property(property: 'mfa_required', type: 'boolean', enum: [true]),
        new OA\Property(property: 'session_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'StaffMfaEnrollmentPending',
    description: 'Password accepted; the role requires TOTP and none is enrolled. Scan `otpauth_url`, keep the recovery codes, then confirm at `POST /dashboard/auth/mfa/enroll`. Nothing is stored until the confirmation succeeds.',
    required: ['mfa_enrollment_required', 'otpauth_url', 'recovery_codes', 'session_ref', 'expires_at'],
    properties: [
        new OA\Property(property: 'mfa_enrollment_required', type: 'boolean', enum: [true]),
        new OA\Property(property: 'otpauth_url', type: 'string', example: 'otpauth://totp/Dahab:ceo%40dahab.test?secret=JBSWY3DPEHPK3PXP&issuer=Dahab&algorithm=SHA1&digits=6&period=30'),
        new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'session_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    ],
)]
class StaffMfaController extends Controller
{
    public function __construct(
        private readonly VerifyStaffMfaAction $verify,
        private readonly EnrollStaffMfaAction $enroll,
    ) {}

    #[OA\Post(
        path: '/dashboard/auth/mfa/verify',
        operationId: 'dashboardAuthMfaVerify',
        summary: 'Complete the TOTP challenge of a pending staff sign-in',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffMfaVerifyRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Session issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'staff', ref: '#/components/schemas/StaffProfile'),
                    new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'mfa_invalid — wrong/replayed code, or an unknown, expired or already-used session_ref', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — per session_ref and per IP', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function verify(MfaVerifyRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->verify->execute(
            $request->validated('session_ref'),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $ctx,
        );

        return response()->json(['data' => [
            'staff' => (new StaffResource($result['staff']))->toArray($request),
            'session' => $result['session']->toArray(),
        ]]);
    }

    #[OA\Post(
        path: '/dashboard/auth/mfa/enroll',
        operationId: 'dashboardAuthMfaEnroll',
        summary: 'Confirm TOTP enrollment for a pending staff sign-in',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffMfaEnrollRequest')),
        responses: [
            new OA\Response(response: 200, description: 'MFA enrolled; session issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'staff', ref: '#/components/schemas/StaffProfile'),
                    new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                    new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'mfa_invalid — wrong code, or an unknown, expired or already-used session_ref', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — per session_ref and per IP', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function enroll(MfaEnrollRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->enroll->execute($request->validated('session_ref'), $request->validated('code'), $ctx);

        return response()->json(['data' => [
            'staff' => (new StaffResource($result['staff']))->toArray($request),
            'session' => $result['session']->toArray(),
            'recovery_codes' => $result['recovery_codes'],
        ]]);
    }
}
