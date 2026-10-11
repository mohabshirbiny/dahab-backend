<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\FounderSecurity\ConfirmUnfreezeAction;
use App\Actions\FounderSecurity\DecideDeviceApprovalAction;
use App\Actions\FounderSecurity\FreezeFounderAction;
use App\Actions\FounderSecurity\ListDeviceApprovalsAction;
use App\Actions\FounderSecurity\ListFounderDevicesAction;
use App\Actions\FounderSecurity\ListFreezesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\FounderSecurity\ConfirmUnfreezeRequest;
use App\Http\Requests\Dashboard\FounderSecurity\DecideDeviceApprovalRequest;
use App\Http\Requests\Dashboard\FounderSecurity\FreezeFounderRequest;
use App\Http\Requests\Dashboard\FounderSecurity\ListDeviceApprovalsRequest;
use App\Http\Requests\Dashboard\FounderSecurity\ListFreezesRequest;
use App\Http\Resources\FounderSecurity\AccountFreezeResource;
use App\Http\Resources\FounderSecurity\DeviceApprovalResource;
use App\Http\Resources\FounderSecurity\FounderDeviceResource;
use App\Models\Staff;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard Founder Security', description: 'Dashboard API — founder-account security (spec 021): held device requests, known devices, freeze and dual unfreeze. Founders only (`staff.founder`), or holders of `founder.unfreeze_second` for the freezes.')]
/**
 * Founder-account security (spec 021): held device requests and known devices, freeze and the
 * dual unfreeze. Founder-only (`staff.founder`), except the freezes list and unfreeze, which
 * holders of `founder.unfreeze_second` also reach; founder status is a flag, never a
 * permission (research R13).
 */
class FounderSecurityController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/founder/device-approvals',
        operationId: 'dashboardFounderDeviceApprovals',
        summary: 'Held founder device requests (spec 021)',
        description: 'Every founder\'s requests, newest first, so both founders see the same history; each row says whether the viewer may decide it. `meta.pending_for_me` counts the requests the viewer may decide now. Founders only. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'refused', 'expired', 'all'], default: 'pending')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated requests', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardDeviceApproval')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'current_page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'pending_for_me', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'founder_only | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function deviceApprovals(ListDeviceApprovalsRequest $request, ListDeviceApprovalsAction $list): AnonymousResourceCollection
    {
        return DeviceApprovalResource::collection($list->handle($request->status(), $request->perPage()))
            ->additional(['meta' => ['pending_for_me' => $list->pendingFor($this->staff($request))]]);
    }

    #[OA\Post(
        path: '/dashboard/founder/device-approvals/{approval}/approve',
        operationId: 'dashboardFounderApproveDevice',
        summary: 'Approve the other founder\'s new device (spec 021)',
        description: 'Only while the request is pending (15 minutes). Never your own (`founder_self_action`). The held device collects its session on its next poll. Audited (`founder.device_approved`, the note as reason); both founders are told. Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [
            new OA\Parameter(name: 'approval', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/DashboardDeviceApprovalDecision')),
        responses: [
            new OA\Response(response: 200, description: 'The decided request', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardDeviceApproval'),
            ])),
            new OA\Response(response: 403, description: 'founder_only | founder_self_action | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'device_approval_not_pending (decided, collected or expired)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function approve(DecideDeviceApprovalRequest $request, string $approval, DecideDeviceApprovalAction $decide): JsonResponse
    {
        return $this->decide($request, $approval, DecideDeviceApprovalAction::APPROVE, $decide);
    }

    #[OA\Post(
        path: '/dashboard/founder/device-approvals/{approval}/refuse',
        operationId: 'dashboardFounderRefuseDevice',
        summary: 'Refuse the other founder\'s new device (spec 021)',
        description: 'Only while the request is pending. Never your own. The held device is told on its next poll (`403 device_approval_refused`). A refusal freezes nothing; freezing is a separate action. Audited (`founder.device_refused`, the note as reason); both founders are told. Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [
            new OA\Parameter(name: 'approval', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/DashboardDeviceApprovalDecision')),
        responses: [
            new OA\Response(response: 200, description: 'The decided request', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardDeviceApproval'),
            ])),
            new OA\Response(response: 403, description: 'founder_only | founder_self_action | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'device_approval_not_pending', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function refuse(DecideDeviceApprovalRequest $request, string $approval, DecideDeviceApprovalAction $decide): JsonResponse
    {
        return $this->decide($request, $approval, DecideDeviceApprovalAction::REFUSE, $decide);
    }

    #[OA\Get(
        path: '/dashboard/founder/devices',
        operationId: 'dashboardFounderDevices',
        summary: 'Each founder\'s known devices (spec 021)',
        description: 'Device refs with first and last seen. A sign-in from a known device is not held. There is no removal (recovery is manual, D21-1). Founders only. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        responses: [
            new OA\Response(response: 200, description: 'Founders and their devices', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardFounderDevices')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'founder_only | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function devices(ListFounderDevicesAction $devices): AnonymousResourceCollection
    {
        return FounderDeviceResource::collection($devices->handle());
    }

    #[OA\Post(
        path: '/dashboard/founder/freeze',
        operationId: 'dashboardFounderFreeze',
        summary: 'Freeze the other founder (spec 021)',
        description: 'Every token of the target is deleted at once, every request of that account answers 403 account_frozen, and its pending device approvals end. A reason is required. Serialised, so both founders can never be frozen. Audited (`founder.frozen`, the reason stored); both founders get an email, a Dashboard notice and an SMS. Founders only; requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardFreezeFounder')),
        responses: [
            new OA\Response(response: 201, description: 'The freeze, with sessions_ended and pending_approvals_ended', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardAccountFreeze'),
            ])),
            new OA\Response(response: 403, description: 'founder_only | founder_self_action | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'already_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'reason_required | freeze_target_invalid (not a founder, unknown, the system actor, or disabled) | validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function freeze(FreezeFounderRequest $request, FreezeFounderAction $freeze): JsonResponse
    {
        $result = $freeze->handle($this->staff($request), (string) $request->validated('staff_id'), $request->validated('reason'));

        return ApiResponse::ok((new AccountFreezeResource($result['freeze']))
            ->withCounts(['sessions_ended' => $result['sessions_ended'], 'pending_approvals_ended' => $result['pending_approvals_ended']])
            ->resolve($request), 201);
    }

    #[OA\Get(
        path: '/dashboard/founder/freezes',
        operationId: 'dashboardFounderFreezes',
        summary: 'Freezes and their unfreeze confirmations (spec 021)',
        description: 'Newest first; `state=open` (default) or `all`. Each row has `can_confirm` and `my_slot` for the viewer. Founders, or holders of founder.unfreeze_second. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'all'], default: 'open')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated freezes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardAccountFreeze')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function freezes(ListFreezesRequest $request, ListFreezesAction $freezes): AnonymousResourceCollection
    {
        return AccountFreezeResource::collection($freezes->handle($request->state(), $request->perPage()));
    }

    #[OA\Post(
        path: '/dashboard/founder/unfreeze',
        operationId: 'dashboardFounderUnfreeze',
        summary: 'Give one unfreeze confirmation (spec 021)',
        description: 'The server picks the slot. A founder who is not frozen gives slot 2, always last (409 unfreeze_order before slot 1; 409 unfreeze_already_confirmed if they gave slot 1): the account is unfrozen, and no session comes back. Otherwise a holder of founder.unfreeze_second gives slot 1 as second_approver (409 unfreeze_already_confirmed when taken). Audited (`founder.unfreeze_confirmed`, slot 2 also `founder.unfrozen`); both founders are told. Requires an Idempotency-Key.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Founder Security'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardConfirmUnfreeze')),
        responses: [
            new OA\Response(response: 200, description: 'The freeze after this confirmation', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/DashboardAccountFreeze'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied | founder_self_action | account_frozen', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'not_frozen | unfreeze_order | unfreeze_already_confirmed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function unfreeze(ConfirmUnfreezeRequest $request, ConfirmUnfreezeAction $confirm): JsonResponse
    {
        $freeze = $confirm->handle($this->staff($request), (string) $request->validated('freeze_id'), $request->validated('note'));

        return ApiResponse::ok((new AccountFreezeResource($freeze))->resolve($request));
    }

    private function decide(DecideDeviceApprovalRequest $request, string $approval, string $decision, DecideDeviceApprovalAction $decide): JsonResponse
    {
        $row = $decide->handle($this->staff($request), $approval, $decision, $request->validated('note'));

        return ApiResponse::ok((new DeviceApprovalResource($row))->resolve($request));
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
