<?php

namespace App\Actions\Overview;

use App\Enums\OrderState;
use App\Enums\StaffPermission;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard Overview's figures (spec 015 FR-016, FR-017, research R10):
 * read-only aggregates, one query per section, each returned only when the
 * viewer holds its code — a section the viewer may not see is absent, not
 * empty. The safety figure and wallets stay on /dashboard/wallets/overview and
 * the gold price on /dashboard/gold-prices/current. Features not built (the
 * first-sale advance, the Rapaport matrix) have no figure here.
 */
final class ShowOverviewAction
{
    /**
     * The acting code of each kind of item waiting for a decision.
     *
     * @var array<string, string>
     */
    public const NEEDS_DECISION = [
        'listing_review' => 'listing.review',
        'withdrawal' => 'withdrawal.release',
        'dispute' => 'dispute.handle',
        'identity_document' => 'identity.review',
        'topup_notice' => 'topup.match',
        'payout_account' => 'payout_account.verify',
        'extension_request' => 'order.extend_deadline',
    ];

    /** @return array<string, mixed> */
    public function handle(Staff $viewer): array
    {
        $can = fn (string $code) => $viewer->can($code);
        $out = [];

        if ($can(StaffPermission::WALLET_VIEW->value)) {
            $out['earnings'] = $this->earnings();
        }
        if ($can(StaffPermission::ORDER_VIEW->value)) {
            $out['orders'] = $this->orders();
            $out['this_month'] = $this->thisMonth();
        }
        $kinds = array_keys(array_filter(self::NEEDS_DECISION, $can));
        if ($kinds !== []) {
            $out['needs_decision'] = $this->needsDecision($kinds);
        }

        return $out;
    }

    /** @return array{month: string, commission: string, spread: string, total: string} */
    private function earnings(): array
    {
        $start = CarbonImmutable::now('Africa/Cairo')->startOfMonth();
        $row = DB::selectOne("
            SELECT COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_commission'), 0)::numeric(18,4)::text AS commission,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_spread'), 0)::numeric(18,4)::text     AS spread
            FROM ledger_posting p
            JOIN account a ON a.account_id = p.account_id
            JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
            WHERE a.kind IN ('dahab_commission', 'dahab_spread') AND t.created_at >= ?
        ", [$start]);

        return ['month' => $start->format('Y-m'), 'commission' => $row->commission, 'spread' => $row->spread,
            'total' => bcadd($row->commission, $row->spread, 4)];
    }

    /**
     * Every open order by state, whatever its age, and the orders that ended in
     * the last 30 days (completed, and every cancellation together): count,
     * value (the locked total) and what the buyers hold on them now (the
     * ledger, by request).
     *
     * @return list<array{state: string, count: int, value: string, held_now: string}>
     */
    private function orders(): array
    {
        $open = array_map(fn (OrderState $s) => $s->value, OrderState::open());
        $since = CarbonImmutable::now()->subDays(30);

        $rows = DB::select("
            WITH held AS (
              SELECT t.buy_request_id, SUM(p.amount) AS amount
              FROM ledger_posting p
              JOIN account a ON a.account_id = p.account_id AND a.kind = 'cust_held'
              JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
              WHERE t.buy_request_id IS NOT NULL
              GROUP BY t.buy_request_id
            ), picked AS (
              SELECT CASE WHEN o.state::text LIKE 'cancelled%' THEN 'cancelled' ELSE o.state::text END AS state,
                     o.locked_total_price, COALESCE(h.amount, 0) AS held
              FROM \"order\" o LEFT JOIN held h ON h.buy_request_id = o.buy_request_id
              WHERE o.state::text = ANY (?::text[])
                 OR (o.state = 'completed' AND o.completed_at >= ?)
                 OR (o.state::text LIKE 'cancelled%' AND (SELECT max(sc.changed_at) FROM order_state_change sc WHERE sc.order_id = o.order_id) >= ?)
            )
            SELECT state, count(*) AS n, SUM(locked_total_price)::numeric(18,4)::text AS value, SUM(held)::numeric(18,4)::text AS held
            FROM picked GROUP BY state
        ", ['{'.implode(',', $open).'}', $since, $since]);

        $order = [...$open, 'completed', 'cancelled'];
        $byState = collect($rows)->keyBy('state');

        return array_values(array_filter(array_map(fn (string $s) => $byState->has($s) ? [
            'state' => $s,
            'count' => (int) $byState[$s]->n,
            'value' => $byState[$s]->value,
            'held_now' => $byState[$s]->held,
        ] : null, $order)));
    }

    /**
     * Oldest first, at most the configured number, each kind only for the
     * holders of its acting code.
     *
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function needsDecision(array $kinds): array
    {
        $parts = [
            'listing_review' => "SELECT 'listing_review' AS kind, l.listing_id::text AS id, c.display_ref AS subject, NULL::text AS amount, l.state_changed_at AS since
                                 FROM listing l JOIN customer c ON c.customer_id = l.seller_id WHERE l.state = 'in_review'",
            'withdrawal' => "SELECT 'withdrawal' AS kind, w.withdrawal_id::text AS id, c.display_ref AS subject, w.amount::numeric(18,4)::text AS amount, w.requested_at AS since
                             FROM withdrawal w JOIN customer c ON c.customer_id = w.customer_id WHERE w.state IN ('requested', 'under_review')",
            'dispute' => "SELECT 'dispute' AS kind, d.dispute_id::text AS id, d.dispute_ref AS subject, NULL::text AS amount, d.opened_at AS since FROM dispute d WHERE d.state <> 'resolved'",
            'identity_document' => "SELECT 'identity_document' AS kind, i.document_id::text AS id, c.display_ref AS subject, NULL::text AS amount, i.created_at AS since
                                    FROM identity_document i JOIN customer c ON c.customer_id = i.customer_id WHERE i.status = 'pending'",
            'topup_notice' => "SELECT 'topup_notice' AS kind, t.topup_id::text AS id, t.reference AS subject, t.claimed_amount::numeric(18,4)::text AS amount, t.submitted_at AS since
                               FROM topup t WHERE t.status IN ('pending', 'on_hold')",
            'payout_account' => "SELECT 'payout_account' AS kind, pa.payout_account_id::text AS id, c.display_ref AS subject, NULL::text AS amount, pa.created_at AS since
                                 FROM payout_account pa JOIN customer c ON c.customer_id = pa.customer_id WHERE pa.state = 'pending_review'",
            'extension_request' => "SELECT 'extension_request' AS kind, r.request_id::text AS id, o.order_ref AS subject, NULL::text AS amount, r.requested_at AS since
                                    FROM order_extension_request r JOIN \"order\" o ON o.order_id = r.order_id WHERE r.state = 'waiting'",
        ];

        $sql = implode("\nUNION ALL\n", array_map(fn (string $k) => '('.$parts[$k].')', $kinds));
        $limit = (int) config('dahab-finance.overview_needs_decision_limit');
        $rows = DB::select("SELECT * FROM ({$sql}) x ORDER BY since, id LIMIT {$limit}");

        $counts = collect(DB::select('SELECT kind, count(*) AS n FROM ('.$sql.') x GROUP BY kind'))->pluck('n', 'kind');

        return array_map(fn ($r) => [
            'kind' => $r->kind,
            'id' => $r->id,
            'subject' => $r->subject,
            'amount' => $r->amount,
            'waiting_since' => CarbonImmutable::parse($r->since)->toIso8601String(),
            'waiting_of_kind' => (int) ($counts[$r->kind] ?? 0),
            'acting_permission' => self::NEEDS_DECISION[$r->kind],
        ], $rows);
    }

    /**
     * This Cairo month: new sellers (customers whose first listing was
     * created this month), pieces that first went live, sales completed, the
     * sell-through (sold ÷ live during the month) and the average days from
     * acceptance to the seller's payment (orders whose balance was paid this month).
     *
     * @return array<string, mixed>
     */
    private function thisMonth(): array
    {
        $start = CarbonImmutable::now('Africa/Cairo')->startOfMonth();
        $row = DB::selectOne("
            SELECT
              (SELECT count(*) FROM (SELECT seller_id FROM listing GROUP BY seller_id HAVING min(created_at) >= ?) s) AS new_sellers,
              (SELECT count(*) FROM listing WHERE listed_at >= ?)                                             AS listed,
              (SELECT count(*) FROM \"order\" WHERE state = 'completed' AND completed_at >= ?)               AS sold,
              (SELECT count(*) FROM listing WHERE listed_at IS NOT NULL AND listed_at < now()
                 AND (state IN ('live', 'reserved') OR state_changed_at >= ?))                                AS live_in_month,
              (SELECT round(avg(extract(epoch FROM t.created_at - o.accepted_at) / 86400)::numeric, 1)::text
                 FROM \"order\" o JOIN ledger_transaction t ON t.order_id = o.order_id AND t.event_kind = 'balance_payment'
                 WHERE t.created_at >= ?)                                                                     AS avg_days
        ", [$start, $start, $start, $start, $start]);

        $live = (int) $row->live_in_month;

        return [
            'month' => $start->format('Y-m'),
            'new_sellers' => (int) $row->new_sellers,
            'pieces_listed' => (int) $row->listed,
            'sold' => (int) $row->sold,
            'sell_through_pct' => $live === 0 ? 0 : (int) round(100 * (int) $row->sold / $live),
            'avg_days_to_pay_sellers' => $row->avg_days,
        ];
    }
}
