<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Finance\BankBookQuery;
use App\Support\Finance\Csv;

/** The bank book of a period as CSV, capped, audited (spec 015 FR-011). */
final class ExportBankBookAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, string $from, string $to): array
    {
        $cap = (int) config('dahab-finance.export_cap');
        $book = new BankBookQuery($from, $to);
        $summary = $book->summary();
        $csv = new Csv(['At', 'What', 'Reference', 'Customer ref', 'How', 'In', 'Out', 'Cash after', 'By'], $cap);

        foreach ($book->rows($summary['opening'], null, $cap + 1) as $row) {
            $s = $row['source'];
            $continue = $csv->row([
                $row['at'], $s['kind_label'] ?? $row['kind'],
                $s['number'] ?? $s['reference'] ?? $s['reverses_txn_id'] ?? '', $s['customer_ref'] ?? '', $s['how'] ?? $s['reason'] ?? '',
                $row['direction'] === 'in' ? $row['amount'] : '', $row['direction'] === 'out' ? $row['amount'] : '',
                $row['cash_after'], $row['actor']['name'] ?? $row['actor']['type'],
            ]);
            if (! $continue) {
                break;
            }
        }

        $result = $csv->finish();
        $this->audit->execute(AuditEvent::BANK_BOOK_EXPORTED, 'success',
            ['from' => $from, 'to' => $to, 'rows' => $result['rows'], 'truncated' => $result['truncated']],
            entityType: 'ledger', actorStaffId: $viewer->staff_id);

        return $result;
    }
}
