<?php

namespace App\Http\Controllers\Api\V1\Dashboard\Auth;

use App\Actions\Auth\Staff\CollectDeviceApprovalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Staff\DeviceApprovalStatusRequest;
use App\Http\Resources\Staff\StaffResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Spec 021 Story 1: the held founder device polls its approval. Public (no token exists yet);
 * the `approval_ref` and the same `X-Device-Id` are what it holds.
 */
class FounderDeviceApprovalStatusController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/auth/device-approval/status',
        operationId: 'dashboardAuthDeviceApprovalStatus',
        summary: 'Poll a held founder sign-in (spec 021)',
        description: 'Send `approval_ref` from the held answer with the same `X-Device-Id`. Pending → `{status: pending}`. Approved → the session, once: the approval is consumed and the device becomes known (`auth.staff.sign_in` with `via: device_approval`); a second poll answers `401 device_approval_invalid`. Public; throttled per ref and per IP.',
        tags: ['Dashboard Auth'],
        parameters: [
            new OA\Parameter(name: 'X-Device-Id', in: 'header', required: true, description: 'The same device id as the held sign-in', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffDeviceApprovalStatusRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Pending, OR approved with the session (first poll only)', content: new OA\JsonContent(oneOf: [
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['pending']),
                        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    ], type: 'object'),
                ]),
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['approved']),
                        new OA\Property(property: 'staff', ref: '#/components/schemas/StaffProfile'),
                        new OA\Property(property: 'session', ref: '#/components/schemas/Session'),
                    ], type: 'object'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'device_approval_invalid (unknown, expired, already collected, or another device) | invalid_credentials (disabled while waiting; nothing minted)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'device_approval_refused | account_frozen (frozen while waiting; nothing minted)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function __invoke(DeviceApprovalStatusRequest $request, CollectDeviceApprovalAction $collect): JsonResponse
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');
        $result = $collect->execute($request->validated('approval_ref'), $ctx);

        if ($result['status'] === 'pending') {
            return response()->json(['data' => $result]);
        }

        return response()->json(['data' => [
            'status' => 'approved',
            'staff' => (new StaffResource($result['staff']))->toArray($request),
            'session' => $result['session']->toArray(),
        ]]);
    }
}
