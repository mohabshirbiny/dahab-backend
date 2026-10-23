<?php

namespace App\Actions\Wallet;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Ledger\StatementQuery;

/**
 * A Wallet statement as CSV (spec 008 FR-016, the spec 006 export pattern):
 * the period, a summary line, then one line per row, capped at 50,000 rows
 * with a closing note; UTF-8 with a BOM. Every export is audited, whatever
 * the view.
 */
final class ExportWalletStatementAction
{
    public const CAP = 50000;

    public function __construct(
        private readonly BuildWalletStatementAction $build,
        private readonly RecordAuditLogAction $audit,
        private readonly int $cap = self::CAP,
    ) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, StatementQuery $query): array
    {
        $statement = $this->build->handle($query, null, $this->cap);
        $s = $statement['summary'];
        $truncated = $statement['next_cursor'] !== null;

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Arabic names
        $put = fn (array $cells) => fputcsv($out, array_map([self::class, 'cell'], $cells), escape: '');

        $put(['Wallet statement', match ($query->view) {
            'customer' => 'Wallet '.$s['customer']['display_ref'].' · '.$s['customer']['full_name'],
            'customers' => 'All customer wallets',
            'dahab' => 'The Dahab wallet',
        }, $query->from.' to '.$query->to]);
        $put(['Opening', $s['opening'], 'In', $s['in'], 'Out', $s['out'], 'Closing', $s['closing']]);

        // Testing fixes item 9: the Dahab view shows commission and spread apart.
        $dahab = $query->view === 'dahab';
        if ($dahab) {
            $put(['Commission in', $s['commission_in'], 'Commission out', $s['commission_out'], 'Spread in', $s['spread_in'], 'Spread out', $s['spread_out'], 'VAT in', $s['vat_in'], 'VAT out', $s['vat_out']]);
            // Spec 023 advances, apart from what Dahab earned.
            $put(['First-sale advance paid', $s['advance_out'], 'First-sale advance back', $s['advance_back']]);
        }
        $split = fn (array $r) => $dahab ? [$r['commission'], $r['spread'], $r['vat'], $r['advance']] : [];
        $splitHead = $dahab ? ['Commission', 'Spread', 'VAT', 'First-sale advance'] : [];

        if ($query->grain === 'each') {
            $put(['When', 'What', 'Wallet', 'Reference', 'By', 'Entered by hand', 'Memo', 'Before', 'In', 'Out', 'After', ...$splitHead]);
            foreach ($statement['rows'] as $r) {
                $put([$r['created_at'], $r['label'], $r['wallet']['display_ref'] ?? '', $r['reference'] ?? '', $r['actor']['name'] ?? '', $r['by_hand'] ? 'Yes' : 'No', $r['memo'] ?? '', $r['before'], $r['in'], $r['out'], $r['after'], ...$split($r)]);
            }
        } else {
            $put(['Period', 'Movements', 'Before', 'In', 'Out', 'After', ...$splitHead]);
            foreach ($statement['rows'] as $r) {
                $put([$r['period'], $r['count'], $r['before'], $r['in'], $r['out'], $r['after'], ...$split($r)]);
            }
        }

        if ($truncated) {
            $put(['Export stopped at '.$this->cap.' rows. Narrow the period to get the rest.']);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $rows = count($statement['rows']);
        $this->audit->execute(
            AuditEvent::LEDGER_STATEMENT_EXPORTED,
            'success',
            $query->toArray() + ['rows' => $rows, 'truncated' => $truncated],
            entityType: $query->view === 'customer' ? 'customer' : 'ledger',
            entityId: $query->customerId,
            actorStaffId: $viewer->staff_id,
        );

        return ['csv' => $csv, 'rows' => $rows, 'truncated' => $truncated];
    }

    /** Neutralise spreadsheet formulas (the spec 006 rule). */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
