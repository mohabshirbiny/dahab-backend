<?php

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Actions\Auth\Customer\LoginCustomerAction;
use App\Actions\Auth\Customer\LogoutCustomerAction;
use App\Actions\Auth\Shared\RotateRefreshTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Customer\LoginCustomerRequest;
use App\Http\Resources\Customer\CustomerResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly LoginCustomerAction $login,
        private readonly LogoutCustomerAction $logout,
        private readonly RotateRefreshTokenAction $rotate,
    ) {}

    #[OA\Post(
        path: '/customer/auth/login',
        operationId: 'customerAuthLogin',
        summary: 'Sign a customer in with phone and password.',
        description: 'From a trusted device the session is issued at once. From a new device the session is held: the response is a `CustomerOtpChallenge` and an SMS code is sent; call /customer/auth/otp/verify from the same device to finish. A request without `X-Device-Id` is refused with invalid_credentials.',
        tags: ['Customer Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Device-Platform', in: 'header', required: false, schema: new OA\Schema(type: 'string', default: 'web')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginCustomerRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Session issued (trusted device) OR OTP challenge (new device)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', oneOf: [
                    new OA\Schema(properties: [
                        new OA\Property(property: 'customer', ref: '#/components/schemas/CustomerProfile'),
                        new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                    ], type: 'object'),
                    new OA\Schema(ref: '#/components/schemas/CustomerOtpChallenge'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'invalid_credentials — same shape for unknown phone, wrong password and a missing X-Device-Id', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'account_pending_verification, account_rejected or account_suspended', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'account_locked (identity bucket) or too_many_requests (IP bucket); Retry-After is set', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function login(LoginCustomerRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $this->login->execute(
            $request->validated('phone'),
            $request->validated('password'),
            $ctx,
        );

        if (isset($result['challenge'])) {
            return response()->json(['data' => CustomerLoginOtpController::challengePayload($result['challenge'])]);
        }

        return response()->json([
            'data' => [
                'customer' => (new CustomerResource($result['customer']))->toArray($request),
                'session' => $result['session']->toArray(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/refresh',
        operationId: 'customerAuthRefresh',
        summary: 'Exchange a customer refresh token for a new access + refresh pair',
        description: 'Present the refresh token as the Bearer credential (ability `customer:refresh`). It rotates on every use; replaying an already-rotated refresh token revokes the whole token family.',
        security: [['customerRefreshBearer' => []]],
        tags: ['Customer Auth'],
        responses: [
            new OA\Response(response: 200, description: 'New session in the same family', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Session'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated (no/expired/non-customer token) or refresh_invalid (replayed refresh token)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — an access token was presented instead of a refresh token', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests — per token family', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function refresh(Request $request): JsonResponse
    {
        $customer = $request->user('customer');

        $session = $this->rotate->execute($customer, $customer->currentAccessToken());

        return response()->json(['data' => $session->toArray()]);
    }

    #[OA\Get(
        path: '/customer/auth/me',
        operationId: 'customerAuthMe',
        summary: 'Get the authenticated customer',
        security: [['customerBearer' => []]],
        tags: ['Customer Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Own customer row with derived trade_allowed; never a password field', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerProfile'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated — no valid customer token (a staff token is not a customer credential)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — a refresh token was presented instead of an access token', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new CustomerResource($request->user('customer')))->toArray($request),
        ]);
    }

    #[OA\Post(
        path: '/customer/auth/logout',
        operationId: 'customerAuthLogout',
        summary: 'Sign out: revoke the current access token and its paired refresh token',
        security: [['customerBearer' => []]],
        tags: ['Customer Auth'],
        responses: [
            new OA\Response(response: 204, description: 'Token family revoked'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — refresh token presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $customer = $request->user('customer');

        $this->logout->current($customer, $customer->currentAccessToken());

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/customer/auth/logout-all',
        operationId: 'customerAuthLogoutAll',
        summary: 'Sign out everywhere: revoke every token issued to the customer',
        security: [['customerBearer' => []]],
        tags: ['Customer Auth'],
        responses: [
            new OA\Response(response: 204, description: 'All tokens revoked'),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'forbidden — refresh token presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function logoutAll(Request $request): JsonResponse
    {
        $this->logout->all($request->user('customer'));

        return response()->json(null, 204);
    }
}
