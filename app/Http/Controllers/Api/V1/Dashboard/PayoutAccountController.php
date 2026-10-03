<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Payouts\Staff\ListPayoutAccountsForReviewAction;
use App\Actions\Payouts\Staff\RefusePayoutAccountAction;
use App\Actions\Payouts\Staff\VerifyPayoutAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Payout\ListPayoutAccountsRequest;
use App\Http\Requests\Dashboard\Payout\RefusePayoutAccountRequest;
use App\Http\Resources\Staff\StaffPayoutAccountResource;
use App\Models\PayoutAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Payout accounts to check (spec 013 US2; Part 2 §9
 * `POST /admin/payout-accounts/{id}/verify`). Permission
 * payout_account.verify (CEO, Finance, Verification). Every POST is
 * idempotent and audited.
 */
class PayoutAccountController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/payout-accounts',
        operationId: 'dashboardPayoutAccounts',
        summary: 'Payout accounts to check',
        description: 'Spec 013 FR-003. state (default pending_review, oldest first; others newest first), q (display reference, E.164 phone or name), keyset. Each item has the customer\'s verified ID name. meta.waiting is the number under review.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending_review', 'active', 'refused', 'removing', 'removed'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffPayoutAccount')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'waiting', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListPayoutAccountsRequest $request, ListPayoutAccountsForReviewAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->state(), $request->validated('q'), $request->cursor(), $perPage);

        return response()->json([
            'data' => $page['rows']->map(fn (PayoutAccount $a) => $this->item($request, $a))->values()->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'waiting' => $page['waiting']],
        ]);
    }

    #[OA\Post(
        path: '/dashboard/payout-accounts/{account}/verify',
        operationId: 'dashboardVerifyPayoutAccount',
        summary: 'The name matches the ID: verify',
        description: 'Spec 013 FR-003. pending_review → active. When the customer has no account in use it becomes the one in use; unless it is their first ever, open withdrawals are cancelled and a pause opens (meta). Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Verified', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffPayoutAccount'),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'pause_until', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'cancelled_withdrawals', type: 'array', items: new OA\Items(type: 'string')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_payout_account_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function verify(Request $request, string $account, VerifyPayoutAccountAction $verify): JsonResponse
    {
        $result = $verify->handle($request->user('staff'), $account, $request->attributes->get('context'));

        return response()->json([
            'data' => $this->item($request, PayoutAccount::query()->with(['customer', 'checkedBy'])->findOrFail($account)),
            'meta' => ['pause_until' => $result['pause']?->pause_until?->toIso8601String(), 'cancelled_withdrawals' => $result['cancelled']],
        ]);
    }

    #[OA\Post(
        path: '/dashboard/payout-accounts/{account}/refuse',
        operationId: 'dashboardRefusePayoutAccount',
        summary: 'Refuse a payout account',
        description: 'Spec 013 FR-003. pending_review → refused (final). The customer is told the reason, never the note. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RefusePayoutAccountRequest')),
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Refused', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffPayoutAccount')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_payout_account_transition', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function refuse(RefusePayoutAccountRequest $request, string $account, RefusePayoutAccountAction $refuse): JsonResponse
    {
        $refuse->handle($request->user('staff'), $account, $request->reason(), (string) $request->validated('note'), $request->attributes->get('context'));

        return response()->json(['data' => $this->item($request, PayoutAccount::query()->with(['customer', 'checkedBy'])->findOrFail($account))]);
    }

    /** @return array<string, mixed> */
    private function item(Request $request, PayoutAccount $account): array
    {
        $resource = StaffPayoutAccountResource::make($account);
        $resource->withCustomer = true;

        return $resource->resolve($request);
    }
}
