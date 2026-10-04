<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Finance\ExportCompensationAction;
use App\Actions\Finance\ListCompensationAction;
use App\Actions\Finance\PayDirectCompensationAction;
use App\Enums\CompensationReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Finance\ListCompensationRequest;
use App\Http\Requests\Dashboard\Finance\PayCompensationRequest;
use App\Http\Resources\Staff\CompensationResource;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The Compensation page (spec 015 US1; Part 2 §9): every payment with the
 * totals and the viewer's caps (compensation.pay or wallet.view), the CSV
 * export, and paying outside a dispute (compensation.pay, capped unless
 * compensation.uncapped). The POST is idempotent and audited.
 */
class CompensationController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/compensation',
        operationId: 'dashboardCompensation',
        summary: 'Every compensation paid',
        description: 'Spec 015 FR-001/FR-002. Newest first, keyset pages. Filters: from/to (Cairo dates, default the last 30 days, at most 366 days), reason, paid_by, customer_id. meta.totals: the period and this Cairo month; meta.caps: the caps and what the viewer has left today (null when uncapped). compensation.pay or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'reason', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['igi_delay', 'dahab_mistake', 'wasted_trip', 'dispute_settlement', 'goodwill'])),
            new OA\Parameter(name: 'paid_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCompensation')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'totals', properties: [
                        new OA\Property(property: 'period', type: 'string'),
                        new OA\Property(property: 'this_month', type: 'string'),
                    ], type: 'object'),
                    new OA\Property(property: 'caps', properties: [
                        new OA\Property(property: 'per_payment', type: 'string'),
                        new OA\Property(property: 'per_day', type: 'string'),
                        new OA\Property(property: 'uncapped', type: 'boolean'),
                        new OA\Property(property: 'left_today', type: 'string', nullable: true),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListCompensationRequest $request, ListCompensationAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($this->staff($request), $request->listQuery(), $request->cursor(), $perPage);

        return response()->json([
            'data' => CompensationResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'totals' => $page['totals'], 'caps' => $page['caps']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/compensation/export',
        operationId: 'dashboardCompensationExport',
        summary: 'The filtered compensation list as CSV',
        description: 'Spec 015 FR-003. Same filters as the list; UTF-8 with BOM; capped (X-Export-Truncated); audited as compensation.list_exported. compensation.pay or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        responses: [
            new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function export(ListCompensationRequest $request, ExportCompensationAction $export): Response
    {
        return self::csv($export->handle($this->staff($request), $request->listQuery()), 'compensation');
    }

    #[OA\Post(
        path: '/dashboard/compensation',
        operationId: 'dashboardCompensationPay',
        summary: 'Pay compensation outside a dispute',
        description: 'Spec 015 FR-004. One balanced compensation entry (external_equity → the customer\'s available), its row with no dispute, optionally naming one of the customer\'s orders. Caps per payment and per Cairo day per payer, shared with dispute payments, unless compensation.uncapped. Only a verified customer (suspended or not). The customer is told by SMS + email. compensation.pay. Idempotent, audited (compensation.paid).',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayCompensationRequest')),
        tags: ['Dashboard Finance'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Paid', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffCompensation')])),
            new OA\Response(response: 403, description: 'permission_denied | compensation_cap_exceeded (details.per_payment, details.left_today) | verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(PayCompensationRequest $request, PayDirectCompensationAction $pay): JsonResponse
    {
        $paid = $pay->handle($this->staff($request), $request->validated('customer_id'), $request->validated('amount'),
            CompensationReason::from($request->validated('reason')), $request->validated('note'),
            $request->validated('order_id'), $request->attributes->get('context'));

        return response()->json(['data' => (new CompensationResource($paid->load(ListCompensationAction::RELATIONS)))->resolve($request)], 201);
    }

    /** @param  array{csv: string, truncated: bool}  $result */
    public static function csv(array $result, string $name): Response
    {
        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'-'.now('Africa/Cairo')->format('Y-m-d').'.csv"',
            'X-Export-Truncated' => $result['truncated'] ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Truncated',
        ]);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
