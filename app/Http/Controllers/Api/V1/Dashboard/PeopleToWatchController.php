<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\PeopleToWatch\ListPeopleToWatchAction;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * People to watch (spec 019 US8, `people_to_watch.view`: CEO and COO): measured figures per
 * customer, never a verdict. Read-only; every read and export is audited.
 */
class PeopleToWatchController extends Controller
{
    #[OA\Get(
        path: '/dashboard/people-to-watch',
        operationId: 'dashboardPeopleToWatch',
        summary: 'Customers with a pattern of transactions, with measured figures',
        description: 'Spec 019 US8. Customers with at least flag.pattern_txn_threshold transactions in the period (`from`, `to`; default the last 30 days), each with four figures and no verdict, strongest first. Read-only; audited (people_to_watch.viewed). Requires people_to_watch.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard People to watch'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The people'),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(Request $request, ListPeopleToWatchAction $list, RecordAuditLogAction $audit): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $result = $list->handle($from, $to);

        $audit->execute(AuditEvent::PEOPLE_TO_WATCH_VIEWED, 'success', ['from' => $result['from'], 'to' => $result['to'], 'rows' => count($result['rows']), 'export' => false],
            entityType: 'people_to_watch', actorStaffId: $request->user('staff')->staff_id, ctx: $request->attributes->get('context'));

        return response()->json(['data' => $result['rows'], 'meta' => ['threshold' => $result['threshold'], 'from' => $result['from'], 'to' => $result['to']]]);
    }

    #[OA\Get(
        path: '/dashboard/people-to-watch/export',
        operationId: 'dashboardPeopleToWatchExport',
        summary: 'People to watch as CSV',
        description: 'Spec 019 US8. The same figures as the list. Audited. Requires people_to_watch.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard People to watch'],
        responses: [new OA\Response(response: 200, description: 'A CSV file')],
    )]
    public function export(Request $request, ListPeopleToWatchAction $list, RecordAuditLogAction $audit): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $result = $list->handle($from, $to);

        $audit->execute(AuditEvent::PEOPLE_TO_WATCH_VIEWED, 'success', ['from' => $result['from'], 'to' => $result['to'], 'rows' => count($result['rows']), 'export' => true],
            entityType: 'people_to_watch', actorStaffId: $request->user('staff')->staff_id, ctx: $request->attributes->get('context'));

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Customer', 'Name', 'Sold', 'Bought', 'Weight under (n)', 'Weighed (of)', 'Average under %', 'Unpaid (n)', 'Accepted as buyer (of)', 'Top counterparty', 'Orders with them', 'Sent back (n)', 'Sent for review (of)']);
            foreach ($result['rows'] as $r) {
                fputcsv($out, [
                    $r['display_ref'], $r['full_name'], $r['sold'], $r['bought'],
                    $r['weight_under']['n'], $r['weight_under']['of'], $r['weight_under']['average_pct'],
                    $r['not_paid']['n'], $r['not_paid']['of'],
                    $r['same_counterparty']['display_ref'] ?? '', $r['same_counterparty']['count'] ?? 0,
                    $r['sent_back']['n'], $r['sent_back']['of'],
                ]);
            }
            fclose($out);
        }, 'people-to-watch-'.$result['from'].'-'.$result['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $v = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($v['to']) ? CarbonImmutable::parse($v['to'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from'])->startOfDay() : $to->subDays(30);

        return [$from, $to];
    }
}
