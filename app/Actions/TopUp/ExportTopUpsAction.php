<?php

namespace App\Actions\TopUp;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\TopUpListQuery;
use App\Support\TopUpMoney;

/**
 * Incoming transfers as CSV (spec 009 FR-015; the spec 006/008 export
 * pattern): the same filters as the list, newest first, capped at 50,000
 * rows with a closing note; UTF-8 with a BOM; spreadsheet formulas
 * neutralised. Every export is audited.
 */
final class ExportTopUpsAction
{
    public const CAP = 50000;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, TopUpListQuery $query): array
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Arabic names
        $put = fn (array $cells) => fputcsv($out, array_map([self::class, 'cell'], $cells), escape: '');

        $put(['Number', 'Origin', 'Submitted at', 'Customer ref', 'Customer name', 'Phone', 'Method', 'Reference', 'Claimed',
            'Status', 'Credited', 'Credited at', 'Receiving account', 'Arrival reference', 'Staff', 'Reject reason']);

        $rows = 0;
        $truncated = false;
        $query->apply(TopUp::query())
            ->with(ListTopUpsAction::RELATIONS)
            ->orderByDesc('topup_no')
            ->limit(self::CAP + 1)
            ->get()
            ->each(function (TopUp $t) use ($put, &$rows, &$truncated) {
                if ($rows === self::CAP) {
                    $truncated = true;

                    return false;
                }
                $rows++;
                $put([
                    $t->number(), $t->origin->label(), $t->submitted_at->toIso8601String(),
                    $t->customer?->display_ref, $t->customer?->full_name, $t->customer?->phone,
                    $t->method->label(), $t->reference, TopUpMoney::format($t->claimed_amount) ?? '',
                    $t->status->label(), TopUpMoney::format($t->credited_amount) ?? '', $t->credited_at?->toIso8601String() ?? '',
                    $t->receivingAccount?->label ?? '', $t->arrival_reference ?? '',
                    ($t->creditedBy ?? $t->rejectedBy ?? $t->heldBy)?->full_name ?? '', $t->reject_reason?->label() ?? '',
                ]);

                return true;
            });

        if ($truncated) {
            $put(['Export stopped at '.self::CAP.' rows. Narrow the dates to get the rest.']);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->execute(
            AuditEvent::TOPUP_LIST_EXPORTED,
            'success',
            $query->toArray() + ['rows' => $rows, 'truncated' => $truncated],
            entityType: 'topup',
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
