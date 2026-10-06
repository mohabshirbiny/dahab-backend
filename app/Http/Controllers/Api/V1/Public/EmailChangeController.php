<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Account\EmailChangeLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ConfirmationTokenRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The email-change link's page (spec 017 FR-010, research R2): no token, the
 * link's secret is the key, as the spec 013 withdrawal link. `read` has no side
 * effect; `confirm` changes the email once. Throttled per IP (public.email_change).
 */
class EmailChangeController extends Controller
{
    #[OA\Post(
        path: '/contact-changes/email/read',
        operationId: 'publicReadEmailChange',
        summary: 'Which address this email-change link confirms',
        description: 'Spec 017 FR-010. No side effect. 410 change_link_invalid when unknown, used or expired.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WithdrawalConfirmationTokenRequest')),
        tags: ['Public'],
        responses: [
            new OA\Response(response: 200, description: 'The pending change', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'email_masked', type: 'string', example: 'm•••@example.com'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
            ], type: 'object')])),
            new OA\Response(response: 410, description: 'change_link_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function read(ConfirmationTokenRequest $request, EmailChangeLinkAction $link): JsonResponse
    {
        $r = $link->read($request->token());

        return response()->json(['data' => ['email_masked' => $r['email_masked'], 'expires_at' => $r['expires_at']->toIso8601String()]]);
    }

    #[OA\Post(
        path: '/contact-changes/email/confirm',
        operationId: 'publicConfirmEmailChange',
        summary: 'Confirm the new email from the link',
        description: 'Spec 017 FR-010, FR-011. The link works once. When the account had an email: open withdrawal confirmation links stop working, withdrawals not yet released are cancelled and new ones pause; the old address is told.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WithdrawalConfirmationTokenRequest')),
        tags: ['Public'],
        responses: [
            new OA\Response(response: 200, description: 'Changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'email_masked', type: 'string'),
                new OA\Property(property: 'pause_until', type: 'string', format: 'date-time', nullable: true),
            ], type: 'object')])),
            new OA\Response(response: 409, description: 'contact_taken', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 410, description: 'change_link_invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function confirm(ConfirmationTokenRequest $request, EmailChangeLinkAction $link): JsonResponse
    {
        $r = $link->confirm($request->token(), $request->attributes->get('context'));

        return response()->json(['data' => ['email_masked' => $r['email_masked'], 'pause_until' => $r['pause']?->pause_until?->toIso8601String()]]);
    }
}
