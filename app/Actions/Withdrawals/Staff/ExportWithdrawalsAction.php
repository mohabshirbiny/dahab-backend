<?php

namespace App\Actions\Withdrawals\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Support\Withdrawals\WithdrawalListQuery;

/**
 * Withdrawals as CSV (spec 013 FR-012; the spec 009 export pattern): the
 * same filters as the list, oldest first, capped with a closing note; UTF-8
 * with a BOM; spreadsheet formulas neutralised. Only `withdrawal.release`
 * reaches it, so the account numbers are in full. Every export is audited.
 */
final class ExportWithdrawalsAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, WithdrawalListQuery $query): array
    {
        $cap = (int) config('dahab-withdrawals.export_cap');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $put = fn (array $cells) => fputcsv($out, array_map([self::class, 'cell'], $cells), escape: '');

        $put(['Number', 'Requested at', 'Customer ref', 'Customer name', 'Amount', 'State', 'On hold', 'Bank', 'Account holder',
            'Account number or IBAN', 'Reviewer', 'Released at', 'Bank transaction number', 'Transfer reference', 'Value date', 'Rejection reason']);

        $rows = 0;
        $truncated = false;
        $query->apply(Withdrawal::query())
            ->with(['customer', 'account', 'reviewer'])
            ->orderBy('requested_at')->orderBy('withdrawal_id')
            ->limit($cap + 1)
            ->get()
            ->each(function (Withdrawal $w) use ($put, $cap, &$rows, &$truncated) {
                if ($rows === $cap) {
                    $truncated = true;

                    return false;
                }
                $rows++;
                $put([
                    $w->number(), $w->requested_at->toIso8601String(), $w->customer?->display_ref, $w->customer?->full_name,
                    bcadd((string) $w->amount, '0', 4), $w->state->label(), $w->isOnHold() ? 'yes' : '',
                    $w->account?->bank_name, $w->account?->account_name, $w->account?->account_number_or_iban,
                    $w->reviewer?->full_name ?? '', $w->released_at?->toIso8601String() ?? '',
                    $w->bank_txn_number ?? '', $w->transfer_reference ?? '', $w->value_date?->toDateString() ?? '',
                    $w->rejection_reason?->label() ?? '',
                ]);

                return true;
            });

        if ($truncated) {
            $put(["Export stopped at {$cap} rows. Narrow the dates to get the rest."]);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->execute(AuditEvent::WITHDRAWAL_LIST_EXPORTED, 'success',
            $query->toArray() + ['rows' => $rows, 'truncated' => $truncated],
            entityType: 'withdrawal', actorStaffId: $viewer->staff_id);

        return ['csv' => $csv, 'rows' => $rows, 'truncated' => $truncated];
    }

    /** Neutralise spreadsheet formulas (the spec 006 rule). */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
