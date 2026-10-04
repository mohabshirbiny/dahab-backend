<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Finance\BuildBankBookAction;
use App\Actions\Finance\ExportBankBookAction;
use App\Actions\Finance\ExportBankMovementsAction;
use App\Actions\Finance\ListBankMovementsAction;
use App\Actions\Finance\RecordBankMovementAction;
use App\Actions\Finance\ViewBankMovementProofAction;
use App\Enums\BankMovementKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Finance\RecordBankMovementRequest;
use App\Http\Resources\Staff\BankMovementResource;
use App\Support\Finance\Period;
use App\Support\Listings\ListingCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The bank book (spec 015 US3; Part 2 §9 "Record a bank movement outside the
 * app"): record a movement (bank.record), read the recorded movements and the
 * bank's postings (bank.record or wallet.view), the proofs (audited) and the
 * CSV exports (audited). The POST is idempotent and audited.
 */
class BankMovementController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/bank-movements',
        operationId: 'dashboardBankMovementRecord',
        summary: 'Record a bank movement outside the app',
        description: 'Spec 015 FR-010. One bank_movement row and, for every kind but own_transfer, one balanced external_bank_movement entry: money in bank −X / external_equity +X, out the reverse. The entry posts now whatever the statement date (a closed day never changes). bank.record. Idempotent, audited (bank.movement_recorded).',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RecordBankMovementRequest')),
        tags: ['Dashboard Finance'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffBankMovement')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | upload_token_invalid', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(RecordBankMovementRequest $request, RecordBankMovementAction $record): JsonResponse
    {
        $movement = $record->handle($request->user('staff'), BankMovementKind::from($request->validated('kind')), $request->validated('direction'),
            $request->validated('amount'), $request->validated('occurred_on'), $request->validated('reason'),
            $request->validated('proof_upload_token'), $request->attributes->get('context'));

        return response()->json(['data' => (new BankMovementResource($movement->load('recorder')))->resolve($request)], 201);
    }

    #[OA\Get(
        path: '/dashboard/bank-movements',
        operationId: 'dashboardBankMovements',
        summary: 'The movements recorded by hand',
        description: 'Spec 015 FR-011. By statement date (from/to, Cairo, default the last 30 days, at most 366 days) and kind; newest recorded first, keyset pages; meta.totals.in/out. bank.record or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffBankMovement')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'totals', properties: [new OA\Property(property: 'in', type: 'string'), new OA\Property(property: 'out', type: 'string')], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, ListBankMovementsAction $list): JsonResponse
    {
        [$from, $to, $kind] = $this->movementFilters($request);
        $request->validate(['cursor' => ['sometimes', 'string', 'max:200'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $perPage = (int) $request->input('per_page', 25);
        $page = $list->handle($from, $to, $kind, ListingCursor::decode($request->input('cursor')), $perPage);

        return response()->json([
            'data' => BankMovementResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'totals' => $page['totals']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/bank-movements/export',
        operationId: 'dashboardBankMovementsExport',
        summary: 'The recorded movements as CSV',
        description: 'Spec 015 FR-011. Same filters; UTF-8 with BOM; capped (X-Export-Truncated); audited (bank.movements_exported). bank.record or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        responses: [new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv'))],
    )]
    public function export(Request $request, ExportBankMovementsAction $export): Response
    {
        [$from, $to, $kind] = $this->movementFilters($request);

        return CompensationController::csv($export->handle($request->user('staff'), $from, $to, $kind), 'bank-movements');
    }

    #[OA\Get(
        path: '/dashboard/bank-movements/{movement}/proof',
        operationId: 'dashboardBankMovementProof',
        summary: 'A movement\'s proof',
        description: 'Spec 015 FR-010. The decrypted file with its type; every view audited (bank.movement_proof_viewed). 404 when the movement has none. bank.record or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [new OA\Parameter(name: 'movement', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The file'),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function proof(Request $request, string $movement, ViewBankMovementProofAction $view): Response
    {
        $file = $view->handle($request->user('staff'), $movement);

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[OA\Get(
        path: '/dashboard/bank-book',
        operationId: 'dashboardBankBook',
        summary: 'Every bank posting of a period',
        description: 'Spec 015 FR-011. Top-ups matched or credited by hand, withdrawals released, movements recorded by hand and reversals, oldest first (by posting), keyset pages; each with direction, amount, cash_after, actor and source. meta.summary: opening, in, out, closing cash (cash = −SUM(bank)). from/to Cairo dates (default the last 30 days, at most 366 days). bank.record or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'ledger_txn_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'kind', type: 'string', enum: ['topup', 'withdrawal', 'external_bank_movement', 'reversal']),
                    new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
                    new OA\Property(property: 'amount', type: 'string'),
                    new OA\Property(property: 'cash_after', type: 'string'),
                    new OA\Property(property: 'actor', type: 'object', description: '{type: staff|system|customer, name}'),
                    new OA\Property(property: 'source', type: 'object', description: 'topup {id, number, reference, customer_ref, how: matched_notice|credited_by_hand, receiving_account} | withdrawal {id, number, customer_ref, bank_txn_number} | bank_movement {id, number, kind, kind_label, reason, occurred_on, has_proof} | reversal {reverses_txn_id, memo}'),
                ], type: 'object')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'summary', properties: [
                        new OA\Property(property: 'opening', type: 'string'), new OA\Property(property: 'in', type: 'string'),
                        new OA\Property(property: 'out', type: 'string'), new OA\Property(property: 'closing', type: 'string'),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function book(Request $request, BuildBankBookAction $book): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $request->validate(['cursor' => ['sometimes', 'string', 'max:40'], 'per_page' => ['sometimes', 'integer', 'between:1,200']]);
        $perPage = (int) $request->input('per_page', 50);
        $page = $book->handle($from, $to, $request->input('cursor'), $perPage);

        return response()->json([
            'data' => array_map(fn (array $row) => array_diff_key($row, ['posting_id' => true]), $page['rows']),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'summary' => $page['summary']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/bank-book/export',
        operationId: 'dashboardBankBookExport',
        summary: 'The bank book of a period as CSV',
        description: 'Spec 015 FR-011. UTF-8 with BOM; capped (X-Export-Truncated); audited (bank.book_exported). bank.record or wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Finance'],
        responses: [new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv'))],
    )]
    public function bookExport(Request $request, ExportBankBookAction $export): Response
    {
        [$from, $to] = $this->period($request);

        return CompensationController::csv($export->handle($request->user('staff'), $from, $to), 'bank-book');
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $request->validate(Period::rules());
        $validator = validator([], []);
        Period::after($request)($validator);
        if ($validator->errors()->isNotEmpty()) {
            throw new ValidationException($validator);
        }

        return Period::of($request, 30);
    }

    /** @return array{0: string, 1: string, 2: BankMovementKind|null} */
    private function movementFilters(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $request->validate(['kind' => ['sometimes', 'string', Rule::enum(BankMovementKind::class)]]);

        return [$from, $to, BankMovementKind::tryFrom((string) $request->input('kind'))];
    }
}
