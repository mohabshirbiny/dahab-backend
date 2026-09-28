<?php

namespace App\Actions\Wallet;

use App\Enums\LedgerEventKind;
use App\Support\Ledger\HistoryCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's own wallet history (spec 008 FR-013, research R7): one row
 * per ledger entry that touched their accounts, newest first, keyset
 * paginated. Each row carries the change to available and to held and both
 * balances after it. A hold is money out of available (and into held); the
 * running balance is available (Clarifications).
 *
 * Only the customer's own lines count: in an entry that also touches another
 * customer (a settlement), the other side is neither visible nor counted.
 */
final class ListCustomerWalletHistoryAction
{
    /** @return array{rows: list<array<string, mixed>>, next_cursor: ?string} */
    public function handle(string $customerId, ?HistoryCursor $cursor, int $perPage): array
    {
        // Balances come from the lines alone; the transaction rows (whose RLS
        // policy costs a subquery per row) are joined for the page only.
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
                SELECT m.*,
                       SUM(m.available_change) OVER (ORDER BY m.seq) AS available_after,
                       SUM(m.held_change)      OVER (ORDER BY m.seq) AS held_after
                FROM mine m
            ), page AS (
                SELECT * FROM running r
                WHERE (?::bigint IS NULL OR r.seq < ?::bigint)
                ORDER BY r.seq DESC
                LIMIT ?
            )
            SELECT pg.seq, pg.ledger_txn_id, t.event_kind::text AS kind, t.created_at,
                   pg.available_change::numeric(18,4)::text AS available_change,
                   pg.held_change::numeric(18,4)::text      AS held_change,
                   pg.available_after::numeric(18,4)::text  AS available_after,
                   pg.held_after::numeric(18,4)::text       AS held_after
            FROM page pg
            JOIN ledger_transaction t ON t.ledger_txn_id = pg.ledger_txn_id
            ORDER BY pg.seq DESC
        ", [$customerId, $cursor?->seq, $cursor?->seq, $perPage + 1]);

        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $last = end($rows) ?: null;

        return [
            'rows' => array_map(fn ($r) => [
                'id' => $r->ledger_txn_id,
                'kind' => LedgerEventKind::from($r->kind)->value,
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
                'available_change' => $r->available_change,
                'held_change' => $r->held_change,
                'available_after' => $r->available_after,
                'held_after' => $r->held_after,
                // Filled when orders and listings exist (their display references).
                'reference' => null,
            ], $rows),
            'next_cursor' => $hasMore && $last !== null ? (new HistoryCursor((int) $last->seq))->encode() : null,
        ];
    }
}
