<?php

namespace App\Actions\Wallet;

use App\Enums\BankMovementKind;
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
                   COALESCE(SUM(held_change), 0)::numeric(18,4)::text AS held_change,
                   COALESCE(SUM(GREATEST(commission_delta, 0)), 0)::numeric(18,4)::text AS commission_in,
                   COALESCE(SUM(GREATEST(-commission_delta, 0)), 0)::numeric(18,4)::text AS commission_out,
                   COALESCE(SUM(GREATEST(spread_delta, 0)), 0)::numeric(18,4)::text AS spread_in,
                   COALESCE(SUM(GREATEST(-spread_delta, 0)), 0)::numeric(18,4)::text AS spread_out,
                   COALESCE(SUM(GREATEST(vat_delta, 0)), 0)::numeric(18,4)::text AS vat_in,
                   COALESCE(SUM(GREATEST(-vat_delta, 0)), 0)::numeric(18,4)::text AS vat_out,
                   COALESCE(SUM(GREATEST(-advance_delta, 0)), 0)::numeric(18,4)::text AS advance_out,
                   COALESCE(SUM(GREATEST(advance_delta, 0)), 0)::numeric(18,4)::text AS advance_back
            FROM dated
        ", $bindings);

        $closing = bcsub(bcadd($opening->balance, $totals->money_in, 4), $totals->money_out, 4);

        $summary = $this->summary($query, $opening->balance, $totals, $closing, bcadd($opening->held, $totals->held_change, 4), $end);
        if ($query->view === 'equity') {
            $summary += $this->equityFigures($entriesSql, $bindings, $closing, $end);
        }

        return [
            'summary' => $summary,
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
        // The Dahab view: the VAT the same entry put on vat_payable (owed, not
        // earned — outside the running balance, shown beside the commission).
        $vat = $query->view === 'dahab' ? "(
                           SELECT COALESCE(SUM(vp.amount), 0)
                           FROM ledger_posting vp
                           JOIN account va ON va.account_id = vp.account_id
                           WHERE vp.ledger_txn_id = pt.ledger_txn_id AND va.kind = 'vat_payable'
                       )" : '0';
        // The equity view touches no customer line itself: name the one customer the same entry
        // paid or charged (an advance, compensation, an adjustment), when there is exactly one.
        $wallet = $query->view === 'equity' ? '(
                           SELECT CASE WHEN COUNT(DISTINCT ca.customer_id) = 1 THEN MIN(ca.customer_id::text) END
                           FROM ledger_posting cp
                           JOIN account ca ON ca.account_id = cp.account_id
                           WHERE cp.ledger_txn_id = pt.ledger_txn_id AND ca.customer_id IS NOT NULL
                       )' : 'CASE WHEN COUNT(DISTINCT a.customer_id) = 1 THEN MIN(a.customer_id::text) END';

        $sql = "
            -- MATERIALIZED: start from the period's transactions (created_at index)
            -- and reach their lines through idx_posting_txn, whatever the planner
            -- guesses about the RLS filters (SC-004).
            period_txn AS MATERIALIZED (
                SELECT t.ledger_txn_id, t.created_at, t.event_kind, t.memo, t.staff_id, t.customer_id, t.order_id
                FROM ledger_transaction t
                WHERE t.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND t.created_at < (?::timestamp AT TIME ZONE '{$tz}')
            ), dated AS (
                SELECT pt.ledger_txn_id,
                       MIN(p.posting_id) AS seq,
                       COALESCE(SUM(p.amount) FILTER (WHERE {$p['run']}), 0) AS delta,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0) AS held_change,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_commission'), 0) AS commission_delta,
                       COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_spread'), 0) AS spread_delta,
                       COALESCE(SUM(p.amount) FILTER (WHERE {$p['advance']}), 0) AS advance_delta,
                       {$vat} AS vat_delta,
                       {$wallet} AS wallet_customer,
                       pt.created_at, pt.event_kind::text AS kind, pt.memo, pt.staff_id, pt.customer_id AS actor_customer_id, pt.order_id
                FROM period_txn pt
                JOIN ledger_posting p ON p.ledger_txn_id = pt.ledger_txn_id
                JOIN account a ON a.account_id = p.account_id
                WHERE ({$p['touch']}) OR ({$p['advance']})
                GROUP BY pt.ledger_txn_id, pt.created_at, pt.event_kind, pt.memo, pt.staff_id, pt.customer_id, pt.order_id
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
                       SUM(d.held_change) OVER () AS t_held,
                       SUM(GREATEST(d.commission_delta, 0)) OVER () AS t_ci,
                       SUM(GREATEST(-d.commission_delta, 0)) OVER () AS t_co,
                       SUM(GREATEST(d.spread_delta, 0)) OVER () AS t_si,
                       SUM(GREATEST(-d.spread_delta, 0)) OVER () AS t_so,
                       SUM(GREATEST(-d.advance_delta, 0)) OVER () AS t_ao,
                       SUM(GREATEST(d.advance_delta, 0)) OVER () AS t_ab,
                       SUM(GREATEST(d.vat_delta, 0)) OVER () AS t_vi,
                       SUM(GREATEST(-d.vat_delta, 0)) OVER () AS t_vo
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
                   pr.commission_delta::numeric(18,4)::text AS commission,
                   pr.spread_delta::numeric(18,4)::text AS spread,
                   pr.t_ci::numeric(18,4)::text AS t_ci,
                   pr.t_co::numeric(18,4)::text AS t_co,
                   pr.t_si::numeric(18,4)::text AS t_si,
                   pr.t_so::numeric(18,4)::text AS t_so,
                   pr.advance_delta::numeric(18,4)::text AS advance,
                   pr.t_ao::numeric(18,4)::text AS t_ao,
                   pr.t_ab::numeric(18,4)::text AS t_ab,
                   pr.vat_delta::numeric(18,4)::text AS vat,
                   pr.t_vi::numeric(18,4)::text AS t_vi,
                   pr.t_vo::numeric(18,4)::text AS t_vo,
                   s.staff_id, s.full_name AS staff_name, s.is_system,
                   ac.full_name AS actor_customer_name,
                   wc.display_ref AS wallet_ref, wc.full_name AS wallet_name,
                   'TOP-' || tu.topup_no AS topup_ref,
                   o.order_ref, bm.kind AS bank_kind
            FROM period pr
            LEFT JOIN staff s ON s.staff_id = pr.staff_id
            LEFT JOIN customer ac ON ac.customer_id = pr.actor_customer_id
            LEFT JOIN customer wc ON wc.customer_id = pr.wallet_customer::uuid
            LEFT JOIN topup tu ON tu.ledger_txn_id = pr.ledger_txn_id
            LEFT JOIN \"order\" o ON o.order_id = pr.order_id
            LEFT JOIN bank_movement bm ON bm.ledger_txn_id = pr.ledger_txn_id
            WHERE (?::bigint IS NULL OR pr.seq > ?::bigint)
            ORDER BY pr.seq
            LIMIT {$limit}
        ", [...$bindings, $opening->balance, $opening->held, $start, $end, $cursor?->seq, $cursor?->seq]);

        $hasMore = $perPage !== null && count($rows) > $perPage;
        $rows = $perPage === null ? $rows : array_slice($rows, 0, $perPage);
        $last = end($rows) ?: null;

        return [
            'rows' => array_map(fn ($r) => $this->eachRow($query, $r), $rows),
            'totals' => isset($rows[0]) ? $this->totalsOf($rows[0]) : null,
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
            // Dahab's own money: what the movement was, the order it belongs to, and the customer paid or charged.
            'equity' => array_replace($row, [
                'label' => self::equityLabel($group = self::equityGroup($kind->value, $r->bank_kind)),
                'reference' => $r->order_ref ?? $r->topup_ref,
            ]) + [
                'group' => $group,
                'wallet' => $r->wallet_customer === null ? null : ['customer_id' => $r->wallet_customer, 'display_ref' => $r->wallet_ref, 'full_name' => $r->wallet_name],
            ],
            // Testing fixes item 9: the two Dahab accounts apart; commission + spread = in − out.
            // VAT is the entry's vat_payable change: beside them, never in the balance.
            // `advance`: the first-sale advance, signed (− paid to a seller, + back at the settlement), outside in/out.
            default => $row + ['commission' => $r->commission, 'spread' => $r->spread, 'vat' => $r->vat, 'advance' => $r->advance],
        };
    }

    /**
     * What an equity movement was: the advance paid or back, compensation, a wallet adjustment,
     * or the bank movement's own kind (capital in, an expense, profit taken out …).
     */
    private static function equityGroup(string $eventKind, ?string $bankKind): string
    {
        return match ($eventKind) {
            LedgerEventKind::FIRST_SALE_PAYOUT->value => 'advance_paid',
            LedgerEventKind::BALANCE_PAYMENT->value => 'advance_back',
            LedgerEventKind::REVERSAL->value => 'wallet_adjustment',
            LedgerEventKind::EXTERNAL_BANK_MOVEMENT->value => $bankKind ?? BankMovementKind::OTHER->value,
            default => $eventKind,
        };
    }

    private static function equityLabel(string $group): string
    {
        return match ($group) {
            'advance_paid' => 'First-sale advance paid to the seller',
            'advance_back' => 'First-sale advance back from the buyer\'s payment',
            'wallet_adjustment' => 'Wallet adjustment by hand',
            'compensation' => 'Compensation to a customer',
            default => BankMovementKind::tryFrom($group)?->label() ?? LedgerEventKind::from($group)->staffLabel(),
        };
    }

    /**
     * The equity view's period, by what moved it (one line per group, in and out),
     * plus the first-sale advances still out and those Dahab absorbed.
     *
     * @param  list<string>  $bindings
     * @return array<string, mixed>
     */
    private function equityFigures(string $entriesSql, array $bindings, string $closing, string $end): array
    {
        $groups = DB::select("
            WITH {$entriesSql}
            SELECT d.kind, bm.kind AS bank_kind,
                   SUM(GREATEST(d.delta, 0))::numeric(18,4)::text AS money_in,
                   SUM(GREATEST(-d.delta, 0))::numeric(18,4)::text AS money_out
            FROM dated d
            LEFT JOIN bank_movement bm ON bm.ledger_txn_id = d.ledger_txn_id
            GROUP BY d.kind, bm.kind
        ", $bindings);

        $byGroup = [];
        foreach ($groups as $g) {
            $key = self::equityGroup($g->kind, $g->bank_kind);
            $byGroup[$key] ??= ['key' => $key, 'label' => self::equityLabel($key), 'in' => '0.0000', 'out' => '0.0000'];
            $byGroup[$key]['in'] = bcadd($byGroup[$key]['in'], $g->money_in, 4);
            $byGroup[$key]['out'] = bcadd($byGroup[$key]['out'], $g->money_out, 4);
        }
        ksort($byGroup);

        $earned = DB::selectOne("
            SELECT COALESCE(SUM(p.amount), 0)::numeric(18,4)::text AS earned
            FROM ledger_posting p
            JOIN account a ON a.account_id = p.account_id
            JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
            WHERE a.kind IN ('dahab_commission','dahab_spread') AND t.created_at < (?::timestamp AT TIME ZONE '".self::TZ."')
        ", [$end])->earned;

        $advances = DB::table('first_sale_advance')->selectRaw("
            COALESCE(SUM(amount_egp) FILTER (WHERE status = 'outstanding'), 0)::numeric(18,4)::text AS outstanding,
            COUNT(*) FILTER (WHERE status = 'outstanding') AS outstanding_count,
            COALESCE(SUM(written_off_amount_egp) FILTER (WHERE status = 'written_off'), 0)::numeric(18,4)::text AS written_off,
            COALESCE(SUM(shortfall_absorbed_egp) FILTER (WHERE status = 'recovered'), 0)::numeric(18,4)::text AS shortfall
        ")->first();

        return [
            'groups' => array_values($byGroup),
            // Dahab's own money and what it earned together, at the end of the period.
            'earned' => $earned,
            'own_money_total' => bcadd($closing, $earned, 4),
            // Today, whatever the period: advances not yet back, and the parts that never come back.
            'advances_outstanding' => $advances->outstanding,
            'advances_outstanding_count' => (int) $advances->outstanding_count,
            'advances_written_off' => $advances->written_off,
            'advances_shortfall' => $advances->shortfall,
        ];
    }

    /** The period totals carried on every row of the page (window aggregates). */
    private function totalsOf(object $r): object
    {
        return (object) [
            'money_in' => $r->t_in, 'money_out' => $r->t_out, 'held_change' => $r->t_held,
            'commission_in' => $r->t_ci, 'commission_out' => $r->t_co, 'spread_in' => $r->t_si, 'spread_out' => $r->t_so,
            'vat_in' => $r->t_vi, 'vat_out' => $r->t_vo,
            'advance_out' => $r->t_ao, 'advance_back' => $r->t_ab,
        ];
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
                       SUM(d.held_change) AS held,
                       SUM(d.commission_delta) AS commission,
                       SUM(d.spread_delta) AS spread,
                       SUM(d.advance_delta) AS advance,
                       SUM(d.vat_delta) AS vat,
                       SUM(GREATEST(d.commission_delta, 0)) AS ci, SUM(GREATEST(-d.commission_delta, 0)) AS co,
                       SUM(GREATEST(d.spread_delta, 0)) AS si, SUM(GREATEST(-d.spread_delta, 0)) AS so,
                       SUM(GREATEST(d.vat_delta, 0)) AS vi, SUM(GREATEST(-d.vat_delta, 0)) AS vo,
                       SUM(GREATEST(-d.advance_delta, 0)) AS ao, SUM(GREATEST(d.advance_delta, 0)) AS ab
                FROM dated d
                WHERE d.created_at >= (?::timestamp AT TIME ZONE '{$tz}') AND d.created_at < (?::timestamp AT TIME ZONE '{$tz}')
                GROUP BY 1
            ), running AS (
                SELECT g.*, ?::numeric + COALESCE(SUM(g.net) OVER (ORDER BY g.period ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS before,
                       SUM(g.money_in) OVER () AS t_in, SUM(g.money_out) OVER () AS t_out, SUM(g.held) OVER () AS t_held,
                       SUM(g.ci) OVER () AS t_ci, SUM(g.co) OVER () AS t_co, SUM(g.si) OVER () AS t_si, SUM(g.so) OVER () AS t_so,
                       SUM(g.ao) OVER () AS t_ao, SUM(g.ab) OVER () AS t_ab,
                       SUM(g.vi) OVER () AS t_vi, SUM(g.vo) OVER () AS t_vo
                FROM grouped g
            )
            SELECT period, n,
                   commission::numeric(18,4)::text AS commission,
                   spread::numeric(18,4)::text AS spread,
                   advance::numeric(18,4)::text AS advance,
                   t_ao::numeric(18,4)::text AS t_ao,
                   t_ab::numeric(18,4)::text AS t_ab,
                   vat::numeric(18,4)::text AS vat,
                   t_vi::numeric(18,4)::text AS t_vi,
                   t_vo::numeric(18,4)::text AS t_vo,
                   t_ci::numeric(18,4)::text AS t_ci,
                   t_co::numeric(18,4)::text AS t_co,
                   t_si::numeric(18,4)::text AS t_si,
                   t_so::numeric(18,4)::text AS t_so,
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
            ] + ($query->view === 'dahab' ? ['commission' => $r->commission, 'spread' => $r->spread, 'vat' => $r->vat, 'advance' => $r->advance] : []), $rows),
            'next_cursor' => $hasMore && $last !== null ? (new StatementCursor(period: $last->period))->encode() : null,
            'totals' => isset($rows[0]) ? $this->totalsOf($rows[0]) : null,
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

            // Testing fixes item 9: commission and spread apart (display only; in = both ins, out = both outs).
            return $summary + [
                'vat_payable' => $vat,
                'commission_in' => $totals->commission_in,
                'commission_out' => $totals->commission_out,
                'spread_in' => $totals->spread_in,
                'spread_out' => $totals->spread_out,
                // The period's VAT, owed on the commission (not in in/out or the balance).
                'vat_in' => $totals->vat_in,
                'vat_out' => $totals->vat_out,
                // Spec 023 advances, apart: Dahab's money fronted to sellers and recovered, not earnings.
                'advance_out' => $totals->advance_out,
                'advance_back' => $totals->advance_back,
            ];
        }

        return $summary;
    }
}
