<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Withdrawals\Staff\ExportWithdrawalsAction;
use App\Actions\Withdrawals\Staff\HoldWithdrawalAction;
use App\Actions\Withdrawals\Staff\ListWithdrawalsAction;
use App\Actions\Withdrawals\Staff\RejectWithdrawalAction;
use App\Actions\Withdrawals\Staff\ReleaseWithdrawalAction;
use App\Actions\Withdrawals\Staff\TakeForReviewAction;
use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Withdrawal\HoldWithdrawalRequest;
use App\Http\Requests\Dashboard\Withdrawal\ListWithdrawalsRequest;
use App\Http\Requests\Dashboard\Withdrawal\RejectWithdrawalRequest;
use App\Http\Requests\Dashboard\Withdrawal\ReleaseWithdrawalRequest;
use App\Http\Resources\Staff\StaffWithdrawalResource;
use App\Models\Staff;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The Withdrawals page (spec 013 US6; Part 2 §9 "Admin — money"). Reading the
 * list and a withdrawal opens with withdrawal.release or wallet.view (read
 * only, numbers masked); every action and the export need withdrawal.release
 * (CEO, Finance — never the COO by default). Every POST is idempotent and audited.
 */
class WithdrawalController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/withdrawals',
        operationId: 'dashboardWithdrawals',
        summary: 'The withdrawals queue',
        description: 'Spec 013 FR-012. Oldest first, keyset pages. Default state requested,under_review. meta.figures: waiting (count, sum), released today (count, sum), on hold, average hours to release over 30 days. withdrawal.release or wallet.view (read only).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, description: 'Comma-separated states', schema: new OA\Schema(type: 'string', example: 'requested,under_review')),
            new OA\Parameter(name: 'held', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Display reference, E.164 phone or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffWithdrawal')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'figures', properties: [
                        new OA\Property(property: 'waiting_count', type: 'integer'),
                        new OA\Property(property: 'waiting_sum', type: 'string'),
                        new OA\Property(property: 'released_today_count', type: 'integer'),
                        new OA\Property(property: 'released_today_sum', type: 'string'),
                        new OA\Property(property: 'on_hold_count', type: 'integer'),
                        new OA\Property(property: 'avg_hours_to_release', type: 'string', nullable: true),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListWithdrawalsRequest $request, ListWithdrawalsAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->listQuery(), $request->cursor(), $perPage);
        [$canAct, $full] = $this->rights($request);

        return response()->json([
            'data' => $page['rows']->map(fn (Withdrawal $w) => StaffWithdrawalResource::item(
                $w, $page['signals'][$w->withdrawal_id] ?? [], $page['available'][$w->customer_id] ?? '0.0000', $canAct, $full,
            ))->values()->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'figures' => $page['figures']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/withdrawals/export',
        operationId: 'dashboardWithdrawalsExport',
        summary: 'The filtered withdrawals as CSV',
        description: 'Spec 013 FR-012. Same filters as the list; UTF-8 with BOM; capped (X-Export-Truncated); audited as withdrawal.list_exported. withdrawal.release.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        responses: [
            new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function export(ListWithdrawalsRequest $request, ExportWithdrawalsAction $export): Response
    {
        $result = $export->handle($this->staff($request), $request->listQuery());

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="withdrawals-'.now()->format('Y-m-d').'.csv"',
            'X-Export-Truncated' => $result['truncated'] ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Truncated',
        ]);
    }

    #[OA\Get(
        path: '/dashboard/withdrawals/{withdrawal}',
        operationId: 'dashboardWithdrawal',
        summary: 'One withdrawal, with its ledger entries',
        description: 'Spec 013 FR-012. withdrawal.release or wallet.view (read only: no can actions, numbers masked).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', allOf: [
                new OA\Schema(ref: '#/components/schemas/StaffWithdrawal'),
                new OA\Schema(properties: [new OA\Property(property: 'ledger', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'kind', type: 'string', enum: ['hold', 'release', 'return']),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'account', type: 'string'), new OA\Property(property: 'amount', type: 'string')], type: 'object')),
                ], type: 'object'))]),
            ])])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $withdrawal, ListWithdrawalsAction $list): JsonResponse
    {
        return $this->detail($request, $withdrawal, $list);
    }

    #[OA\Post(
        path: '/dashboard/withdrawals/{withdrawal}/review',
        operationId: 'dashboardWithdrawalReview',
        summary: 'Take a withdrawal for review',
        description: 'Spec 013 (Part 2 §9 review): requested → under_review. withdrawal.release. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWithdrawal')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_withdrawal_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function review(Request $request, string $withdrawal, TakeForReviewAction $take, ListWithdrawalsAction $list): JsonResponse
    {
        $take->handle($this->staff($request), $withdrawal, $request->attributes->get('context'));

        return $this->detail($request, $withdrawal, $list);
    }

    #[OA\Post(
        path: '/dashboard/withdrawals/{withdrawal}/hold',
        operationId: 'dashboardWithdrawalHold',
        summary: 'Put a withdrawal under review on hold',
        description: 'Spec 013 US6: a flag with a reason, a message the customer is told and a staff note. A held withdrawal cannot be released. withdrawal.release. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HoldWithdrawalRequest')),
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWithdrawal')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_withdrawal_transition', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function hold(HoldWithdrawalRequest $request, string $withdrawal, HoldWithdrawalAction $hold, ListWithdrawalsAction $list): JsonResponse
    {
        $hold->hold($this->staff($request), $withdrawal, $request->reason(), (string) $request->validated('message'),
            (string) $request->validated('note'), $request->attributes->get('context'));

        return $this->detail($request, $withdrawal, $list);
    }

    #[OA\Post(
        path: '/dashboard/withdrawals/{withdrawal}/unhold',
        operationId: 'dashboardWithdrawalUnhold',
        summary: 'Remove the hold',
        description: 'Spec 013 US6. withdrawal.release. Body { note? }. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWithdrawal')])),
            new OA\Response(response: 409, description: 'illegal_withdrawal_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function unhold(Request $request, string $withdrawal, HoldWithdrawalAction $hold, ListWithdrawalsAction $list): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $hold->unhold($this->staff($request), $withdrawal, $note, $request->attributes->get('context'));

        return $this->detail($request, $withdrawal, $list);
    }

    #[OA\Post(
        path: '/dashboard/withdrawals/{withdrawal}/release',
        operationId: 'dashboardWithdrawalRelease',
        summary: 'Record the bank transfer and release',
        description: 'Spec 013 FR-013 (Part 2 §9 release). Send the transfer at the bank first, then record it. under_review, not held, the account the customer\'s and active or removing. Ledger: held −X, bank +X. No pause re-check (every open withdrawal was cancelled at the account change). withdrawal.release. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReleaseWithdrawalRequest')),
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Released', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWithdrawal')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'withdrawal_on_hold | illegal_withdrawal_transition | payout_account_not_active', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function release(ReleaseWithdrawalRequest $request, string $withdrawal, ReleaseWithdrawalAction $release, ListWithdrawalsAction $list): JsonResponse
    {
        $release->handle($this->staff($request), $withdrawal, (string) $request->validated('bank_txn_number'),
            $request->validated('transfer_reference'), $request->validated('value_date'), $request->attributes->get('context'));

        return $this->detail($request, $withdrawal, $list);
    }

    #[OA\Post(
        path: '/dashboard/withdrawals/{withdrawal}/reject',
        operationId: 'dashboardWithdrawalReject',
        summary: 'Reject a withdrawal',
        description: 'Spec 013 (Part 2 §9 reject). requested / under_review → rejected; held → available. The customer is told the reason, never the note. withdrawal.release. Idempotent, audited.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RejectWithdrawalRequest')),
        tags: ['Dashboard Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffWithdrawal')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_withdrawal_transition', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function reject(RejectWithdrawalRequest $request, string $withdrawal, RejectWithdrawalAction $reject, ListWithdrawalsAction $list): JsonResponse
    {
        $reject->handle($this->staff($request), $withdrawal, $request->reason(), (string) $request->validated('note'), $request->attributes->get('context'));

        return $this->detail($request, $withdrawal, $list);
    }

    private function detail(Request $request, string $withdrawal, ListWithdrawalsAction $list): JsonResponse
    {
        $d = $list->show($withdrawal);
        [$canAct, $full] = $this->rights($request);

        return response()->json(['data' => StaffWithdrawalResource::item($d['withdrawal'], $d['signals'], $d['available'], $canAct, $full)
            + ['ledger' => $d['ledger']]]);
    }

    /** @return array{0: bool, 1: bool} may act, sees full numbers */
    private function rights(Request $request): array
    {
        $staff = $this->staff($request);
        $release = $staff->hasPermissionTo(StaffPermission::WITHDRAWAL_RELEASE->value, 'staff');

        return [$release, $release || $staff->hasPermissionTo(StaffPermission::PAYOUT_ACCOUNT_VERIFY->value, 'staff')];
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
