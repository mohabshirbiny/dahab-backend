<?php

namespace App\Actions\Audit;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Audit\AuditCursor;
use App\Support\Audit\AuditEntryPresenter;
use App\Support\Audit\AuditFilters;
use App\Support\Audit\AuditQuery;

/**
 * The filtered log as a CSV Excel opens (spec 006 FR-009, FR-011): every
 * matching entry, not one page, under the same visibility rules, capped;
 * UTF-8 with a BOM; cells that could run as formulas are neutralised. The
 * export itself is recorded once.
 *
 * Built inside the request (not streamed after it) so the database actor
 * scope that lets staff read audit_log is still in place.
 */
final class ExportAuditEntriesAction
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly AuditQuery $query,
        private readonly AuditEntryPresenter $presenter,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, AuditFilters $filters): array
    {
        $cap = (int) config('dahab-audit.export_max_rows', 50000);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['When', 'Who', 'What', 'Subject', 'Before', 'After', 'Reason', 'IP', 'Device fingerprint'], escape: '');

        $rows = 0;
        $truncated = false;
        $cursor = null;

        do {
            $chunk = $this->query->after($this->query->filtered($viewer, $filters), $cursor)
                ->limit(min(self::CHUNK, $cap - $rows + 1))->get();

            if ($rows + $chunk->count() > $cap) {
                $truncated = true;
                $chunk = $chunk->take($cap - $rows);
            }

            foreach ($this->presenter->present($chunk, details: true) as $entry) {
                fputcsv($out, array_map([self::class, 'cell'], [
                    $entry['at'],
                    self::who($entry['actor']),
                    $entry['label'],
                    $entry['subject'],
                    $entry['before_summary'],
                    $entry['after_summary'],
                    $entry['reason'],
                    $entry['ip'],
                    $entry['device_fingerprint'],
                ]), escape: '');
                $rows++;
            }

            $last = $chunk->last();
            $cursor = $last === null ? null : new AuditCursor((string) $last->getRawOriginal('created_at'), $last->audit_id);
        } while (! $truncated && $chunk->count() === self::CHUNK && $rows < $cap);

        if ($truncated) {
            fputcsv($out, ["Export stopped at {$cap} entries. Narrow the period to get the rest."], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->execute(
            AuditEvent::AUDIT_LOG_EXPORTED,
            'success',
            ['filters' => $filters->toArray(), 'rows' => $rows, 'truncated' => $truncated],
            entityType: 'audit_log',
            actorStaffId: $viewer->staff_id,
        );

        return ['csv' => $csv, 'rows' => $rows, 'truncated' => $truncated];
    }

    /** @param  array<string, mixed>  $actor */
    private static function who(array $actor): string
    {
        return match ($actor['type']) {
            'system' => 'System',
            'staff' => (string) $actor['name'],
            default => 'Customer '.$actor['ref'],
        };
    }

    /** CSV injection guard: a cell Excel would read as a formula is prefixed with an apostrophe. */
    private static function cell(mixed $value): string
    {
        $v = $value === null ? '' : (string) $value;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
