<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Wallet\BuildWalletStatementAction;
use App\Actions\Wallet\ExportWalletStatementAction;
use App\Actions\Wallet\ShowCustomerWalletAction;
use App\Actions\Wallet\ShowLedgerOverviewAction;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Wallet\WalletStatementRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Staff wallet reads (spec 008 US4). Every route requires `wallet.view`
 * (seeded to CEO and Finance, not the COO). Nothing here moves money.
 */
class WalletController extends Controller
{
    #[OA\Get(
        path: '/dashboard/wallets/overview',
        operationId: 'dashboardWalletOverview',
        summary: 'Customer wallets, the bank\'s cash and the safety figure',
        description: 'Spec 008 FR-018. available + held = total_owed (what Dahab owes customers); bank = the cash in the bank (−SUM of the bank account\'s lines, research R15); headroom = bank − total_owed (negative means customer money is short); system_total = the sum of every ledger line, which must be "0.0000". Requires wallet.view. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Wallets'],
        responses: [
            new OA\Response(response: 200, description: 'The figures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'available', type: 'string', example: '2346160.0000'),
                new OA\Property(property: 'held', type: 'string', example: '612400.0000'),
                new OA\Property(property: 'total_owed', type: 'string', example: '2958560.0000'),
                new OA\Property(property: 'bank', type: 'string', example: '3142880.0000'),
                new OA\Property(property: 'headroom', type: 'string', example: '184320.0000'),
                new OA\Property(property: 'system_total', type: 'string', example: '0.0000'),
            ], type: 'object')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function overview(ShowLedgerOverviewAction $overview): JsonResponse
    {
        return response()->json(['data' => $overview->handle()]);
    }

    #[OA\Get(
        path: '/dashboard/customers/{customer}/wallet',
        operationId: 'dashboardCustomerWallet',
        summary: 'One customer\'s wallet (the customer file panel)',
        description: 'Spec 008 FR-017. Available, held and total, derived from the ledger. Requires wallet.view. Not audited (opening the customer file already is).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Wallets'],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The wallet', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerWallet')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function customerWallet(string $customer, ShowCustomerWalletAction $wallet): JsonResponse
    {
        $id = Customer::query()->whereKey($customer)->valueOrFail('customer_id');

        return response()->json(['data' => $wallet->handle($id)]);
    }

    #[OA\Get(
        path: '/dashboard/wallet-statement',
        operationId: 'dashboardWalletStatement',
        summary: 'A Wallet statement: one customer, all customers, or the Dahab wallet',
        description: 'Spec 008 FR-016. Opening, in, out and closing for a Cairo-day period, and every movement (grain=each) or one line per Cairo day/month with the balance before and after, oldest first, keyset-paginated. view=customer runs on available (a hold is an out, with held_after beside it); view=customers runs on available + held (a hold moves nothing, see moved_to_held); view=dahab runs on commission + spread (vat_payable in the summary). opening + in − out = closing; before + in − out = after on every row. by_hand marks entries a staff member (not the system) recorded. The first page of a view=customer statement is audited (ledger.statement.viewed). Requires wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Wallets'],
        parameters: [
            new OA\Parameter(name: 'view', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'customers', 'dahab'])),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Required for view=customer, not allowed otherwise', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: true, description: 'Cairo date, inclusive', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Cairo date, inclusive; at most 366 days after from', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'grain', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['each', 'day', 'month'], default: 'each')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The statement', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'summary', type: 'object', description: 'view, from, to, opening, in, out, closing; + available, held, total, customer (view=customer); + vat_payable (view=dahab)'),
                    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object', description: 'grain=each: id, created_at, kind, label, reference, memo, by_hand, actor{type,name}, before, in, out, after (+ held_after | wallet, moved_to_held). grain=day|month: period, count, before, in, out, after')),
                ], type: 'object'),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found (unknown customer_id)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function statement(WalletStatementRequest $request, BuildWalletStatementAction $build, RecordAuditLogAction $audit): JsonResponse
    {
        $query = $request->statementQuery();
        $cursor = $request->cursor();

        if ($query->customerId !== null) {
            Customer::query()->whereKey($query->customerId)->firstOrFail();
        }

        $perPage = $request->perPage();
        $statement = $build->handle($query, $cursor, $perPage);

        if ($query->view === 'customer' && $cursor === null) {
            $audit->execute(
                AuditEvent::LEDGER_STATEMENT_VIEWED,
                'success',
                $query->toArray(),
                entityType: 'customer',
                entityId: $query->customerId,
                actorStaffId: $request->user('staff')->staff_id,
            );
        }

        return response()->json([
            'data' => ['summary' => $statement['summary'], 'rows' => $statement['rows']],
            'meta' => ['per_page' => $perPage, 'next_cursor' => $statement['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/wallet-statement/export',
        operationId: 'dashboardExportWalletStatement',
        summary: 'Download a Wallet statement as CSV (opens in Excel)',
        description: 'Same query as the statement, without paging; capped at 50,000 rows (X-Export-Truncated: true when the cap cut it). UTF-8 with a BOM. Every export is audited (ledger.statement.exported). Requires wallet.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Wallets'],
        parameters: [
            new OA\Parameter(name: 'view', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'customers', 'dahab'])),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'grain', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['each', 'day', 'month'], default: 'each')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied | account_frozen', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found (unknown customer_id)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function export(WalletStatementRequest $request, ExportWalletStatementAction $export): Response
    {
        $query = $request->statementQuery();

        if ($query->customerId !== null) {
            Customer::query()->whereKey($query->customerId)->firstOrFail();
        }

        $result = $export->handle($request->user('staff'), $query);
        $name = 'wallet-statement-'.$query->view.'-'.$query->from.'-'.$query->to.'.csv';

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Export-Truncated' => $result['truncated'] ? 'true' : 'false',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Export-Truncated',
        ]);
    }
}
