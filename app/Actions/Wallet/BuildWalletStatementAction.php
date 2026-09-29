<?php

namespace App\Actions\Wallet;

use App\Enums\LedgerEventKind;
use App\Support\Ledger\StatementCursor;
use App\Support\Ledger\StatementQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A Wallet statement (spec 008 FR-016, SC-006; research R7): opening, in,
 * out and closing for a Cairo-day period, and one row per ledger entry (or
 * per Cairo day / month) with the balance before and after it, oldest
 * first, keyset-paginated. Every figure is derived from the ledger lines on
 * each request, so it always reconciles:
 *   opening + in − out = closing, and before + in − out = after per row.
 *
 * Entries are ordered by their sequence key, MIN(posting_id).
 */
final class BuildWalletStatementAction
{
    private const TZ = 'Africa/Cairo';

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function handle(StatementQuery $query, ?StatementCursor $cursor, ?int $perPage): array
    {
        $start = $query->from.' 00:00:00';
        $end = Carbon::parse($query->to, self::TZ)->addDay()->format('Y-m-d').' 00:00:00';
        [$entriesSql, $bindings] = $this->entries($query, $start, $end);
        $opening = $this->opening($query, $start);

        $page = $query->grain === 'each'
            ? $this->eachRows($query, $entriesSql, $bindings, $start, $end, $opening, $cursor, $perPage)
            : $this->periodRows($query, $entriesSql, $bindings, $start, $end, $opening->balance, $cursor, $perPage);

        // The rows query carries the period totals (window aggregates over the
        // whole period); only an empty page needs them computed on their own.
        $totals = $page['totals'] ?? DB::selectOne("
            WITH {$entriesSql}
            SELECT COALESCE(SUM(GREATEST(delta, 0)), 0)::numeric(18,4)::text AS money_in,
                   COALESCE(SUM(GREATEST(-delta, 0)), 0)::numeric(18,4)::text AS money_out,
                   COALESCE(SUM(held_change), 0)::numeric(18,4)::text AS held_change
            FROM dated
        ", $bindings);

        $closing = bcsub(bcadd($opening->balance, $totals->money_in, 4), $totals->money_out, 4);

        return [
            'summary' => $this->summary($query, $opening->balance, $totals, $closing, bcadd($opening->held, $totals->held_change, 4), $end),
            'rows' => $page['rows'],
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /**
     * The balance (and, for one customer, the held balance) at the start of
     * the period: the total now minus everything since the start. Both parts
     * are index reads — the per-account index for the total, the created_at
     * index for the recent entries — so a long history is never regrouped
     * (SC-004).
     */
    private function opening(StatementQuery $query, string $start): object
    {
        $p = $query->predicates();
        $held = "({$p['touch']}) AND a.kind = 'cust_held'";
        $since = fn (string $where) => "
            SELECT COALESCE(SUM(p.amount), 0)
            FROM ledger_transaction t
            JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
            JOIN account a ON a.account_id = p.account_id
            WHERE t.created_at >= (?::timestamp AT TIME ZONE '".self::TZ."') AND {$where}";
        $total = fn (string $where) => "
            SELECT COALESCE(SUM(p.amount), 0)
            FROM ledger_posting p
            JOIN account a ON a.account_id = p.account_id
            WHERE {$where}";

        return DB::selectOne("
            SELECT (({$total($p['run'])}) - ({$since($p['run'])}))::numeric(18,4)::text AS balance,
                   (({$total($held)}) - ({$since($held)}))::numeric(18,4)::text AS held
        ", [...$p['bindings'], $start, ...$p['bindings'], ...$p['bindings'], $start, ...$p['bindings']]);
    }

    /**
     * The per-entry CTE chain for the view, limited to the period: the
     * period's transactions first (created_at index), then their lines.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function entries(StatementQuery $query, string $start, string $end): array
    {
        $p = $query->predicates();
        $tz = self::TZ;

        $sql = "
            -- MATERIALIZED: start from the period's transactions (created_at index)
            -- and reach their lines through idx_posting_txn, whatever the planner
            -- guesses about the RLS filters (SC-004).
            period_txn AS MATERIALIZED (
                SELECT t.ledger_txn_id, t.created_at, t.event_kind, t.memo, t.staff_id, t.customer_id
                FROM ledger_transaction t
                WHERE t.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND t.created_at < (?::timestamp AT TIME ZONE '{$tz}')
            ), dated AS (
                SELECT pt.ledger_txn_id,
                       MIN(p.posting_id) AS seq,
                       COALESCE(SUM(p.amount) FILTER (WHERE {$p['run']}), 0) AS delta,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0) AS held_change,
                       CASE WHEN COUNT(DISTINCT a.customer_id) = 1 THEN MIN(a.customer_id::text) END AS wallet_customer,
                       pt.created_at, pt.event_kind::text AS kind, pt.memo, pt.staff_id, pt.customer_id AS actor_customer_id
                FROM period_txn pt
                JOIN ledger_posting p ON p.ledger_txn_id = pt.ledger_txn_id
                JOIN account a ON a.account_id = p.account_id
                WHERE {$p['touch']}
                GROUP BY pt.ledger_txn_id, pt.created_at, pt.event_kind, pt.memo, pt.staff_id, pt.customer_id
            )
        ";

        // Placeholders in order: the period, `run` (SELECT), `touch` (WHERE).
        return [$sql, [$start, $end, ...$p['bindings'], ...$p['bindings']]];
    }

    /**
     * @param  list<string>  $bindings
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string, totals: ?object}
     */
    private function eachRows(StatementQuery $query, string $entriesSql, array $bindings, string $start, string $end, object $opening, ?StatementCursor $cursor, ?int $perPage): array
    {
        $tz = self::TZ;
        $limit = $perPage === null ? 'ALL' : (string) ($perPage + 1);

        $rows = DB::select("
            WITH {$entriesSql}, period AS (
                SELECT d.*,
                       ?::numeric + COALESCE(SUM(d.delta) OVER w, 0) AS before,
                       ?::numeric + COALESCE(SUM(d.held_change) OVER w, 0) + d.held_change AS held_after,
                       SUM(GREATEST(d.delta, 0)) OVER () AS t_in,
                       SUM(GREATEST(-d.delta, 0)) OVER () AS t_out,
                       SUM(d.held_change) OVER () AS t_held
                FROM dated d
                WHERE d.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND d.created_at < (?::timestamp AT TIME ZONE '{$tz}')
                WINDOW w AS (ORDER BY d.seq ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING)
            )
            SELECT pr.seq, pr.ledger_txn_id, pr.created_at, pr.kind, pr.memo, pr.wallet_customer,
                   pr.before::numeric(18,4)::text AS before,
                   GREATEST(pr.delta, 0)::numeric(18,4)::text AS money_in,
                   GREATEST(-pr.delta, 0)::numeric(18,4)::text AS money_out,
                   (pr.before + pr.delta)::numeric(18,4)::text AS after,
                   pr.held_change::numeric(18,4)::text AS held_change,
                   pr.held_after::numeric(18,4)::text AS held_after,
                   pr.t_in::numeric(18,4)::text AS t_in,
                   pr.t_out::numeric(18,4)::text AS t_out,
                   pr.t_held::numeric(18,4)::text AS t_held,
                   s.staff_id, s.full_name AS staff_name, s.is_system,
                   ac.full_name AS actor_customer_name,
                   wc.display_ref AS wallet_ref,
                   'TOP-' || tu.topup_no AS topup_ref
            FROM period pr
            LEFT JOIN staff s ON s.staff_id = pr.staff_id
            LEFT JOIN customer ac ON ac.customer_id = pr.actor_customer_id
            LEFT JOIN customer wc ON wc.customer_id = pr.wallet_customer::uuid
            LEFT JOIN topup tu ON tu.ledger_txn_id = pr.ledger_txn_id
            WHERE (?::bigint IS NULL OR pr.seq > ?::bigint)
            ORDER BY pr.seq
            LIMIT {$limit}
        ", [...$bindings, $opening->balance, $opening->held, $start, $end, $cursor?->seq, $cursor?->seq]);

        $hasMore = $perPage !== null && count($rows) > $perPage;
        $rows = $perPage === null ? $rows : array_slice($rows, 0, $perPage);
        $last = end($rows) ?: null;

        return [
            'rows' => array_map(fn ($r) => $this->eachRow($query, $r), $rows),
            'totals' => isset($rows[0]) ? (object) ['money_in' => $rows[0]->t_in, 'money_out' => $rows[0]->t_out, 'held_change' => $rows[0]->t_held] : null,
            'next_cursor' => $hasMore && $last !== null ? (new StatementCursor(seq: (int) $last->seq))->encode() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function eachRow(StatementQuery $query, object $r): array
    {
        $kind = LedgerEventKind::from($r->kind);
        $actor = match (true) {
            $r->staff_id !== null && $r->is_system => ['type' => 'system', 'name' => $r->staff_name],
            $r->staff_id !== null => ['type' => 'staff', 'name' => $r->staff_name],
            default => ['type' => 'customer', 'name' => $r->actor_customer_name],
        };

        $row = [
            'id' => $r->ledger_txn_id,
            'created_at' => Carbon::parse($r->created_at)->setTimezone(self::TZ)->toIso8601String(),
            'kind' => $kind->value,
            'label' => $kind->staffLabel(),
            // A top-up's number (spec 009 R12); orders and listings fill theirs when they exist.
            'reference' => $r->topup_ref,
            'memo' => $r->memo,
            'by_hand' => $actor['type'] === 'staff',
            'actor' => $actor,
            'before' => $r->before,
            'in' => $r->money_in,
            'out' => $r->money_out,
            'after' => $r->after,
        ];

        return match ($query->view) {
            'customer' => $row + ['held_after' => $r->held_after],
            'customers' => $row + [
                'wallet' => $r->wallet_customer === null ? null : ['customer_id' => $r->wallet_customer, 'display_ref' => $r->wallet_ref],
                'moved_to_held' => $r->held_change,
            ],
            default => $row,
        };
    }

    /**
     * @param  list<string>  $bindings
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string, totals: ?object}
     */
    private function periodRows(StatementQuery $query, string $entriesSql, array $bindings, string $start, string $end, string $opening, ?StatementCursor $cursor, ?int $perPage): array
    {
        $tz = self::TZ;
        $format = $query->grain === 'day' ? 'YYYY-MM-DD' : 'YYYY-MM';
        $limit = $perPage === null ? 'ALL' : (string) ($perPage + 1);

        $rows = DB::select("
            WITH {$entriesSql}, grouped AS (
                SELECT to_char(d.created_at AT TIME ZONE '{$tz}', '{$format}') AS period,
                       COUNT(*) AS n,
                       SUM(GREATEST(d.delta, 0)) AS money_in,
                       SUM(GREATEST(-d.delta, 0)) AS money_out,
                       SUM(d.delta) AS net,
                       SUM(d.held_change) AS held
                FROM dated d
                WHERE d.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND d.created_at < (?::timestamp AT TIME ZONE '{$tz}')
                GROUP BY 1
            ), running AS (
                SELECT g.*, ?::numeric + COALESCE(SUM(g.net) OVER (ORDER BY g.period ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS before,
                       SUM(g.money_in) OVER () AS t_in, SUM(g.money_out) OVER () AS t_out, SUM(g.held) OVER () AS t_held
                FROM grouped g
            )
            SELECT period, n,
                   before::numeric(18,4)::text AS before,
                   money_in::numeric(18,4)::text AS money_in,
                   money_out::numeric(18,4)::text AS money_out,
                   (before + net)::numeric(18,4)::text AS after,
                   t_in::numeric(18,4)::text AS t_in,
                   t_out::numeric(18,4)::text AS t_out,
                   t_held::numeric(18,4)::text AS t_held
            FROM running
            WHERE (?::text IS NULL OR period > ?::text)
            ORDER BY period
            LIMIT {$limit}
        ", [...$bindings, $start, $end, $opening, $cursor?->period, $cursor?->period]);

        $hasMore = $perPage !== null && count($rows) > $perPage;
        $rows = $perPage === null ? $rows : array_slice($rows, 0, $perPage);
        $last = end($rows) ?: null;

        return [
            'rows' => array_map(fn ($r) => [
                'period' => $r->period,
                'count' => (int) $r->n,
                'before' => $r->before,
                'in' => $r->money_in,
                'out' => $r->money_out,
                'after' => $r->after,
            ], $rows),
            'next_cursor' => $hasMore && $last !== null ? (new StatementCursor(period: $last->period))->encode() : null,
            'totals' => isset($rows[0]) ? (object) ['money_in' => $rows[0]->t_in, 'money_out' => $rows[0]->t_out, 'held_change' => $rows[0]->t_held] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(StatementQuery $query, string $opening, object $totals, string $closing, string $heldClosing, string $end): array
    {
        $summary = [
            'view' => $query->view,
            'from' => $query->from,
            'to' => $query->to,
            'opening' => $opening,
            'in' => $totals->money_in,
            'out' => $totals->money_out,
            'closing' => $closing,
        ];

        if ($query->view === 'customer') {
            $customer = DB::table('customer')->where('customer_id', $query->customerId)->first(['customer_id', 'display_ref', 'full_name']);

            return $summary + [
                'available' => $closing,
                'held' => $heldClosing,
                'total' => bcadd($closing, $heldClosing, 4),
                'customer' => ['id' => $customer->customer_id, 'display_ref' => $customer->display_ref, 'full_name' => $customer->full_name],
            ];
        }

        if ($query->view === 'dahab') {
            $vat = DB::selectOne("
                SELECT COALESCE(SUM(p.amount), 0)::numeric(18,4)::text AS vat
                FROM ledger_posting p
                JOIN account a ON a.account_id = p.account_id
                JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
                WHERE a.kind = 'vat_payable' AND t.created_at < (?::timestamp AT TIME ZONE '".self::TZ."')
            ", [$end])->vat;

            return $summary + ['vat_payable' => $vat];
        }

        return $summary;
    }
}
