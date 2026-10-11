<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Staff\ConfirmStaffPasswordResetAction;
use App\Actions\Auth\Staff\RequestStaffPasswordResetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\StaffResetConfirmRequest;
use App\Http\Requests\Auth\Staff\StaffResetRequestRequest;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Staff password recovery (spec 020 US2): an emailed single-use link, then a new password.
 * Public routes. No endpoint ever returns or sets a known password, and MFA is untouched.
 */
class StaffPasswordResetController extends Controller
{
    public function __construct(
        private readonly RequestStaffPasswordResetAction $request,
        private readonly ConfirmStaffPasswordResetAction $confirm,
    ) {}

    #[OA\Post(
        path: '/dashboard/auth/password/reset-request',
        operationId: 'dashboardAuthPasswordResetRequest',
        summary: 'Ask for a set-password link by email',
        description: 'Sends a single-use link (30 minutes) to the address when it belongs to an active staff account. The answer is identical for an unknown, disabled or system address. Older open links of the member are voided.',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffResetRequestRequest')),
        responses: [
            new OA\Response(response: 202, description: 'Accepted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'If that address belongs to an active staff account, a link was sent.'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function requestReset(StaffResetRequestRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $this->request->execute($request->validated('email'), $ctx);

        return response()->json(['data' => [
            'message' => 'If that address belongs to an active staff account, a link was sent.',
        ]], 202);
    }

    #[OA\Post(
        path: '/dashboard/auth/password/reset-confirm',
        operationId: 'dashboardAuthPasswordResetConfirm',
        summary: 'Set the password with the emailed link',
        description: 'Spends the link and creates or replaces the password. Every staff token of the member is deleted. No session is issued and the authenticator enrolment is untouched: the next sign-in is email, password and the authenticator code.',
        tags: ['Dashboard Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffResetConfirmRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Password set', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'password_changed', type: 'boolean', example: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'reset_link_invalid (unknown, used, voided, expired or disabled member) or validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function confirmReset(StaffResetConfirmRequest $request): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $this->confirm->execute($request->validated('token'), $request->validated('password'), $ctx);

        return response()->json(['data' => ['password_changed' => true]]);
    }
}
