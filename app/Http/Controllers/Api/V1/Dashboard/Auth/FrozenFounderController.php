<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Staff\ConfirmFrozenFounderUnfreezeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\FrozenConfirmUnfreezeRequest;
use App\Support\ApiResponse;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Spec 021 C1: the frozen founder's first unfreeze confirmation. Public and tokenless: a frozen
 * account never receives a session.
 */
class FrozenFounderController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/auth/frozen/confirm-unfreeze',
        operationId: 'dashboardAuthFrozenConfirmUnfreeze',
        summary: 'The frozen founder gives the first unfreeze confirmation (spec 021)',
        description: 'Email + password + a TOTP code (or a recovery code), from a device already known for this founder (`X-Device-Id`). Records slot 1 (`frozen_founder`); the founder who is not frozen confirms last with `POST /dashboard/founder/unfreeze`. **No token is ever issued.** Checks in order: password (401 invalid_credentials, uniform with sign-in), an open freeze (409 not_frozen), a known device (422 device_id_required / 403 device_not_known), the second factor with the sign-in replay guard (401 mfa_invalid), slot 1 free (409 unfreeze_already_confirmed). Shares the sign-in and MFA limiters. Audited (`founder.unfreeze_confirmed`, failures `founder.unfreeze_confirm_failed`).',
        tags: ['Dashboard Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: true, description: 'A device already known for this founder', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffFrozenConfirmUnfreezeRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Slot 1 recorded', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'freeze_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'slot', type: 'integer', enum: [1]),
                    new OA\Property(property: 'waiting_for', type: 'string', enum: ['founder']),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'invalid_credentials | mfa_invalid', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'device_not_known', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'not_frozen | unfreeze_already_confirmed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | device_id_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'account_locked | too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function __invoke(FrozenConfirmUnfreezeRequest $request, ConfirmFrozenFounderUnfreezeAction $confirm): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return ApiResponse::ok($confirm->execute(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request->validated('note'),
            $ctx,
        ));
    }
}
