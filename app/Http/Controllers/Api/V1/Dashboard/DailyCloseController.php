<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Finance\CloseDayAction;
use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Finance\CloseDayRequest;
use App\Http\Resources\Staff\DailyCloseResource;
use App\Models\DailyClose;
use App\Models\Staff;
use App\Support\Finance\CloseFigures;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Daily closing (spec 015 US4; Part 2 §9 "Close the day"): the day's books
 * and its stored close (day.close or wallet.view), recent days, and closing an
 * ended day against the statement balance (day.close). The POST is idempotent
 * and audited.
 */
class DailyCloseController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/daily-close',
        operationId: 'dashboardDailyClose',
        summary: 'A day\'s books and its close',
        description: 'Spec 015 FR-013. date (Cairo, default today): a past day at its midnight cut-off, today live. close: the stored row or null; can_close: the day has ended, is not locked and you hold day.close. day.close or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'The day', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'date', type: 'string', format: 'date'),
                new OA\Property(property: 'ended', type: 'boolean'),
                new OA\Property(property: 'books', properties: [
                    new OA\Property(property: 'bank', type: 'string', description: 'The ledger\'s bank cash'),
                    new OA\Property(property: 'customer_available', type: 'string'),
                    new OA\Property(property: 'customer_held', type: 'string'),
                    new OA\Property(property: 'customer_liability', type: 'string'),
                    new OA\Property(property: 'dahab_wallet', type: 'string', description: 'Commission + spread, earned and not withdrawn'),
                    new OA\Property(property: 'escrow', type: 'string'),
                    new OA\Property(property: 'vat_payable', type: 'string'),
                    new OA\Property(property: 'movements_in', type: 'string', description: 'Recorded by hand with this statement date'),
                    new OA\Property(property: 'movements_out', type: 'string'),
                ], type: 'object'),
                new OA\Property(property: 'close', ref: '#/components/schemas/StaffDailyClose', nullable: true),
                new OA\Property(property: 'can_close', type: 'boolean'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->day($request, (string) $request->input('date', CarbonImmutable::now('Africa/Cairo')->toDateString()))]);
    }

    #[OA\Get(
        path: '/dashboard/daily-closes',
        operationId: 'dashboardDailyCloses',
        summary: 'Recent closes',
        description: 'Spec 015 FR-013. The stored closes in from/to (Cairo, default this month), newest first; meta.days_closed (locked), meta.days_in_period (ended days), meta.last_difference (the latest non-zero difference). day.close or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The days', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffDailyClose')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'days_closed', type: 'integer'),
                    new OA\Property(property: 'days_in_period', type: 'integer'),
                    new OA\Property(property: 'last_difference', type: 'object', nullable: true, description: '{date, difference, explanation}'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        $from = CarbonImmutable::parse((string) $request->input('from', $today->startOfMonth()->toDateString()), 'Africa/Cairo');
        $to = CarbonImmutable::parse((string) $request->input('to', $today->toDateString()), 'Africa/Cairo');
        if ($from->diffInDays($to) > 366) {
            abort(response()->json(['code' => 'validation_failed', 'message' => 'The period can be at most 366 days.', 'errors' => ['to' => ['The period can be at most 366 days.']]], 422));
        }

        $rows = DailyClose::query()->with(['saver', 'closer'])
            ->whereBetween('close_date', [$from->toDateString(), $to->toDateString()])->orderByDesc('close_date')->get();
        $last = DailyClose::query()->where('difference', '<>', 0)->where('close_date', '<=', $to->toDateString())->orderByDesc('close_date')->first();
        $lastEnded = $to->lessThan($today) ? $to : $today->subDay();

        return response()->json([
            'data' => DailyCloseResource::collection($rows)->resolve($request),
            'meta' => [
                'days_closed' => $rows->where('is_locked', true)->count(),
                'days_in_period' => $lastEnded->lessThan($from) ? 0 : (int) $from->diffInDays($lastEnded) + 1,
                'last_difference' => $last === null ? null : [
                    'date' => $last->close_date->toDateString(), 'difference' => bcadd((string) $last->difference, '0', 4), 'explanation' => $last->explanation,
                ],
            ],
        ]);
    }

    #[OA\Post(
        path: '/dashboard/daily-close',
        operationId: 'dashboardDailyCloseStore',
        summary: 'Close a day',
        description: 'Spec 015 FR-014. Only an ended Cairo day (day_not_ended). The books are the ledger at the day\'s midnight cut-off; difference = bank_balance − books bank. 0 locks the day; a non-zero difference locks it only with an explanation, otherwise it is saved unlocked (state: saved) and can be closed again. A locked day: day_already_closed. day.close. Idempotent, audited (day.closed / day.saved).',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CloseDayRequest')),
        tags: ['Dashboard Finance'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Locked or saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'state', type: 'string', enum: ['locked', 'saved']),
                new OA\Property(property: 'close', ref: '#/components/schemas/StaffDailyClose'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'day_already_closed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'day_not_ended | validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(CloseDayRequest $request, CloseDayAction $close): JsonResponse
    {
        $row = $close->handle($request->user('staff'), $request->validated('date'), $request->validated('bank_balance'),
            $request->validated('explanation'), $request->attributes->get('context'));

        return response()->json(['data' => [
            'state' => $row->is_locked ? 'locked' : 'saved',
            'close' => (new DailyCloseResource($row->load(['saver', 'closer'])))->resolve($request),
        ]]);
    }

    /** @return array<string, mixed> */
    private function day(Request $request, string $date): array
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $close = DailyClose::query()->with(['saver', 'closer'])->whereKey($date)->first();
        $ended = CloseFigures::ended($date);

        return [
            'date' => $date,
            'ended' => $ended,
            'books' => CloseFigures::at($date),
            'close' => $close === null ? null : (new DailyCloseResource($close))->resolve($request),
            'can_close' => $ended && ! ($close?->is_locked ?? false) && $staff->can(StaffPermission::DAY_CLOSE->value),
        ];
    }
}
