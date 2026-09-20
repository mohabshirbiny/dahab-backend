<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Shared\RotateRefreshTokenAction;
use App\Actions\Auth\Staff\LoginStaffAction;
use App\Actions\Auth\Staff\LogoutStaffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\LoginStaffRequest;
use App\Http\Resources\Staff\StaffResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StaffAuthController extends Controller
{
    public function __construct(
        private readonly RotateRefreshTokenAction $rotate,
        private readonly LoginStaffAction $login,
        private readonly LogoutStaffAction $logout,
    ) {}

    #[OA\Post(
        path: '/dashboard/auth/login',
        operationId: 'dashboardAuthLogin',
        summary: 'Sign a staff member in with email and password',
        description: 'Roles ceo/coo/finance (and any staff who enrolled voluntarily) do not receive a session here: the response is an MFA challenge (`mfa_required`) or, when no TOTP is enrolled yet, an enrollment payload (`mfa_enrollment_required`). Finish with `POST /dashboard/auth/mfa/verify` or `/mfa/enroll`.',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginStaffRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Session issued, OR an MFA challenge, OR MFA enrollment required', content: new OA\JsonContent(oneOf: [
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'staff', ref: '#/components/schemas/StaffProfile'),
                        new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                    ], type: 'object'),
                ]),
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/StaffMfaChallenge'),
                ]),
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/StaffMfaEnrollmentPending'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'invalid_credentials — same shape for unknown email, wrong password and a disabled account', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'account_frozen — correct password on a frozen account', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'account_locked (email bucket) or too_many_requests (IP bucket); Retry-After is set', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function login(LoginStaffRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->login->execute($request->validated('email'), $request->validated('password'), $ctx);

        if (isset($result['session'])) {
            return response()->json(['data' => [
                'staff' => (new StaffResource($result['staff']))->toArray($request),
                'session' => $result['session']->toArray(),
            ]]);
        }

        $result['expires_at'] = $result['expires_at']->toIso8601String();

        return response()->json(['data' => $result]);
    }

    #[OA\Post(
        path: '/dashboard/auth/logout',
        operationId: 'dashboardAuthLogout',
        summary: 'Sign out: revoke the current access token and its paired refresh token',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Auth'],
        responses: [
            new OA\Response(response: 204, description: 'Token family revoked'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — refresh token presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $staff = $request->user('staff');

        $this->logout->current($staff, $staff->currentAccessToken());

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/dashboard/auth/logout-all',
        operationId: 'dashboardAuthLogoutAll',
        summary: 'Sign out everywhere: revoke every token issued to the staff member',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Auth'],
        responses: [
            new OA\Response(response: 204, description: 'All tokens revoked'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — refresh token presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function logoutAll(Request $request): JsonResponse
    {
        $this->logout->all($request->user('staff'));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/dashboard/auth/refresh',
        operationId: 'dashboardAuthRefresh',
        summary: 'Exchange a staff refresh token for a new access + refresh pair',
        description: 'Present the refresh token as the Bearer credential (ability `staff:refresh`). It rotates on every use; replaying an already-rotated refresh token revokes the whole token family.',
        security: [['dashboardRefreshBearer' => []]],
        tags: ['Dashboard Auth'],
        responses: [
            new OA\Response(response: 200, description: 'New session in the same family', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Session'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated (no/expired/non-staff token) or refresh_invalid (replayed refresh token)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — an access token was presented instead of a refresh token', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — per token family', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function refresh(Request $request): JsonResponse
    {
        $staff = $request->user('staff');

        $session = $this->rotate->execute($staff, $staff->currentAccessToken());

        return response()->json(['data' => $session->toArray()]);
    }

    #[OA\Get(
        path: '/dashboard/auth/me',
        operationId: 'dashboardAuthMe',
        summary: 'Get the authenticated staff member with their roles and effective permissions',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Own staff row, Spatie roles and effective permission set', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffProfile'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated — no valid staff token (a customer token is not a dashboard credential)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — a refresh token was presented instead of an access token', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new StaffResource($request->user('staff')))->toArray($request),
        ]);
    }
}
