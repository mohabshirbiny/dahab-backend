<?php

namespace App\Actions\Wallet;

use App\Models\Customer;
use App\Support\Wallet\CustomerStatementLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own wallet statement for a Cairo-day period (wallet statement PDF, item 10):
 * the same rows, labels and running balance as `GET /customer/me/wallet/transactions`
 * (ListCustomerWalletHistoryAction), newest first, with the available and held balances at the
 * start and the end. Every figure comes from the customer's own ledger lines, read under their
 * own forced-RLS scope: there is no customer id parameter to the endpoint.
 *
 *   opening + money in − money out = closing (available), row by row.
 *
 * A withdrawal sent to the bank leaves held, not available: like the app, its row shows the held
 * change as the amount and leaves the available balance as it was.
 */
final class BuildCustomerStatementAction
{
    private const TZ = 'Africa/Cairo';

    /** A year of history is far below this; the PDF says so if it is ever reached. */
    public const MAX_ROWS = 5000;

    /**
     * @return array{customer: array{full_name: ?string, display_ref: string}, from: string, to: string,
     *     opening: array{available: string, held: string}, closing: array{available: string, held: string},
     *     money_in: string, money_out: string, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function handle(Customer $customer, string $from, string $to): array
    {
        $start = $from.' 00:00:00';
        $end = Carbon::parse($to, self::TZ)->addDay()->format('Y-m-d').' 00:00:00';
        $tz = self::TZ;

        $rows = DB::select("
            WITH mine AS (
                SELECT p.ledger_txn_id,
                       MIN(p.posting_id) AS seq,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'), 0) AS available_change,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0)      AS held_change
                FROM ledger_posting p
                JOIN account a ON a.account_id = p.account_id
                WHERE a.customer_id = ?
                GROUP BY p.ledger_txn_id
            ), running AS (
                SELECT m.*, t.created_at, t.event_kind::text AS kind, t.withdrawal_id, t.order_id,
                       SUM(m.available_change) OVER (ORDER BY m.seq) AS available_after,
                       SUM(m.held_change)      OVER (ORDER BY m.seq) AS held_after
                FROM mine m
                JOIN ledger_transaction t ON t.ledger_txn_id = m.ledger_txn_id
            )
            SELECT r.seq, r.ledger_txn_id, r.kind, r.created_at,
                   r.available_change::numeric(18,4)::text AS available_change,
                   r.held_change::numeric(18,4)::text      AS held_change,
                   r.available_after::numeric(18,4)::text  AS available_after,
                   r.held_after::numeric(18,4)::text       AS held_after,
                   COALESCE(o.order_ref, 'TOP-' || tu.topup_no, 'WD-' || w.withdrawal_no) AS reference
            FROM running r
            LEFT JOIN topup tu ON tu.ledger_txn_id = r.ledger_txn_id
            LEFT JOIN withdrawal w ON w.withdrawal_id = r.withdrawal_id
            LEFT JOIN \"order\" o ON o.order_id = r.order_id
            WHERE r.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND r.created_at < (?::timestamp AT TIME ZONE '{$tz}')
            ORDER BY r.seq DESC
            LIMIT ".(self::MAX_ROWS + 1).'
        ', [$customer->customer_id, $start, $end]);

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        // The balances at the start and at the end: everything before each moment.
        $balanceAt = fn (string $moment) => DB::selectOne("
            SELECT COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'), 0)::numeric(18,4)::text AS available,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0)::numeric(18,4)::text      AS held
            FROM ledger_posting p
            JOIN account a ON a.account_id = p.account_id
            JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
            WHERE a.customer_id = ? AND t.created_at < (?::timestamp AT TIME ZONE '{$tz}')
        ", [$customer->customer_id, $moment]);
        $opening = $balanceAt($start);
        $closing = $balanceAt($end);

        $in = '0.0000';
        $out = '0.0000';
        $lines = [];
        foreach ($rows as $r) {
            $key = CustomerStatementLabels::keyOf($r->kind, $r->available_change);
            // The amount the app shows: the change to available, or the held change for money sent to the bank.
            $amount = $key === 'withdrawal' ? $r->held_change : $r->available_change;
            if (bccomp($r->available_change, '0', 4) > 0) {
                $in = bcadd($in, $r->available_change, 4);
            } else {
                $out = bcadd($out, bcmul($r->available_change, '-1', 4), 4);
            }
            $label = CustomerStatementLabels::of($key);
            $lines[] = [
                'id' => $r->ledger_txn_id,
                'created_at' => Carbon::parse($r->created_at)->setTimezone(self::TZ)->toIso8601String(),
                'kind' => $r->kind,
                'label_en' => $label['en'],
                'label_ar' => $label['ar'],
                'reference' => $r->reference,
                'amount' => $amount,
                'available_change' => $r->available_change,
                'held_change' => $r->held_change,
                'available_after' => $r->available_after,
                'held_after' => $r->held_after,
            ];
        }

        return [
            'customer' => ['full_name' => $customer->full_name, 'display_ref' => (string) $customer->display_ref],
            'from' => $from,
            'to' => $to,
            'opening' => ['available' => (string) $opening->available, 'held' => (string) $opening->held],
            'closing' => ['available' => (string) $closing->available, 'held' => (string) $closing->held],
            'money_in' => $in,
            'money_out' => $out,
            'rows' => $lines,
            'truncated' => $truncated,
        ];
    }
}
