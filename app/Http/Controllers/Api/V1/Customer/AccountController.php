<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Account\ChangePasswordAction;
use App\Actions\Account\ConfirmPhoneChangeAction;
use App\Actions\Account\CustomerSessionsAction;
use App\Actions\Account\RequestEmailChangeAction;
use App\Actions\Account\RequestPhoneChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Account\ChangePasswordRequest;
use App\Http\Requests\Customer\Account\ConfirmPhoneChangeRequest;
use App\Http\Requests\Customer\Account\RequestEmailChangeRequest;
use App\Http\Requests\Customer\Account\RequestPhoneChangeRequest;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The customer's own account (spec 017 US1–US3): phone and email changes,
 * the password, the sessions. Every signed-in customer may use them, whatever
 * the state (Q7); each change is a security event — audited, rate-limited,
 * the old contact told.
 */
#[OA\Schema(
    schema: 'CustomerSession',
    required: ['session_id', 'platform', 'user_agent', 'device_known', 'started_at', 'last_active_at', 'is_current'],
    properties: [
        new OA\Property(property: 'session_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android', 'web'], nullable: true, description: 'Null for a session from before spec 017'),
        new OA\Property(property: 'user_agent', type: 'string', nullable: true),
        new OA\Property(property: 'device_known', type: 'boolean', description: 'The device is trusted: signing in there needs no code'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'last_active_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_current', type: 'boolean'),
    ],
)]
class AccountController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/customer/me/phone-change',
        operationId: 'customerRequestPhoneChange',
        summary: 'Send a code to a new phone number',
        description: 'Spec 017 FR-001. The code goes to the new number only and replaces any earlier request. 3 an hour.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RequestPhoneChangeRequest')),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Code sent', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'phone_masked', type: 'string'),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'contact_taken', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | same_contact', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function requestPhoneChange(RequestPhoneChangeRequest $request, RequestPhoneChangeAction $action): JsonResponse
    {
        $r = $action->handle($this->customer($request), (string) $request->validated('phone'), $this->ctx($request));

        return ApiResponse::ok([
            'challenge_id' => $r['challenge_id'],
            'expires_at' => $r['expires_at']->toIso8601String(),
            'phone_masked' => $r['phone_masked'],
        ], 201);
    }

    #[OA\Post(
        path: '/customer/me/phone-change/{challenge}/confirm',
        operationId: 'customerConfirmPhoneChange',
        summary: 'Prove the new number and move the account to it',
        description: 'Spec 017 FR-002. Withdrawals not yet released are cancelled and new ones pause (withdrawal.account_change_pause_hours); every other session ends and every other trusted device is forgotten; the old number and the email are told. 5 tries per code; 5 confirmations per 15 minutes.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfirmPhoneChangeRequest')),
        tags: ['Customer Account'],
        parameters: [
            new OA\Parameter(name: 'challenge', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'customer', ref: '#/components/schemas/CustomerProfile'),
                new OA\Property(property: 'pause_until', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'cancelled_withdrawals', type: 'array', items: new OA\Items(type: 'string')),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'contact_taken', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'change_code_invalid (details.tries_left)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'change_code_locked | too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function confirmPhoneChange(ConfirmPhoneChangeRequest $request, string $challenge, ConfirmPhoneChangeAction $action): JsonResponse
    {
        $customer = $this->customer($request);
        $r = $action->handle($customer, $challenge, (string) $request->validated('code'),
            $this->currentFamily($customer), $this->ctx($request)?->deviceFingerprintHash, $this->ctx($request));

        return ApiResponse::ok([
            'customer' => (new CustomerResource($r['customer']->refresh()))->toArray($request),
            'pause_until' => $r['pause']?->pause_until?->toIso8601String(),
            'cancelled_withdrawals' => $r['cancelled'],
        ]);
    }

    #[OA\Post(
        path: '/customer/me/email-change',
        operationId: 'customerRequestEmailChange',
        summary: 'Send a confirmation link to a new email address',
        description: 'Spec 017 FR-010. A single-use link for 30 minutes; a new request spends the older ones. 3 an hour.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RequestEmailChangeRequest')),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Link sent', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'email_masked', type: 'string'),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'contact_taken', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | same_contact', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function requestEmailChange(RequestEmailChangeRequest $request, RequestEmailChangeAction $action): JsonResponse
    {
        $r = $action->handle($this->customer($request), (string) $request->validated('email'), $this->ctx($request));

        return ApiResponse::ok(['expires_at' => $r['expires_at']->toIso8601String(), 'email_masked' => $r['email_masked']], 201);
    }

    #[OA\Post(
        path: '/customer/me/password',
        operationId: 'customerChangePassword',
        summary: 'Change the password',
        description: 'Spec 017 FR-012. Every other session ends; trusted devices stay; the customer is told. A wrong current password counts against the sign-in limiter. 5 per 15 minutes.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChangePasswordRequest')),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'signed_out_sessions', type: 'integer'),
            ], type: 'object')])),
            new OA\Response(response: 422, description: 'validation_failed | current_password_wrong', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $customer = $this->customer($request);

        return ApiResponse::ok($action->handle($customer, (string) $request->validated('current_password'),
            (string) $request->validated('password'), $this->currentFamily($customer), $this->ctx($request)));
    }

    #[OA\Get(
        path: '/customer/me/sessions',
        operationId: 'customerSessions',
        summary: 'The devices signed in to the account',
        description: 'Spec 017 FR-020. Open sessions, newest activity first; the one making the call is marked.',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        responses: [
            new OA\Response(response: 200, description: 'Sessions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerSession'))])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function sessions(Request $request, CustomerSessionsAction $action): JsonResponse
    {
        $customer = $this->customer($request);

        return ApiResponse::ok($action->list($customer, $this->currentFamily($customer)));
    }

    #[OA\Post(
        path: '/customer/me/sessions/{session}/sign-out',
        operationId: 'customerSignOutSession',
        summary: 'Sign another device out',
        description: 'Spec 017 FR-020. Ends the session and, when it is tied to a device, every session of that device, and forgets the device (its next sign-in needs a code). Not the current session (use logout). 10 a minute.',
        security: [['customerBearer' => []]],
        tags: ['Customer Account'],
        parameters: [
            new OA\Parameter(name: 'session', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Signed out', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'signed_out_sessions', type: 'integer'),
                new OA\Property(property: 'device_forgotten', type: 'boolean'),
            ], type: 'object')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'current_session', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function signOutSession(Request $request, string $session, CustomerSessionsAction $action): JsonResponse
    {
        $customer = $this->customer($request);

        return ApiResponse::ok($action->signOut($customer, $session, $this->currentFamily($customer), $this->ctx($request)));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }

    private function ctx(Request $request): ?RequestContext
    {
        return $request->attributes->get('context');
    }

    private function currentFamily(Customer $customer): ?string
    {
        $family = $customer->currentAccessToken()?->getAttribute('family_id');

        return $family === null ? null : (string) $family;
    }
}
