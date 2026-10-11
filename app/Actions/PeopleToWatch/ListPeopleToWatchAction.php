<?php

namespace App\Actions\PeopleToWatch;

use App\Enums\SettingKey;
use App\Support\Pricing\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * People to watch (spec 019 US8, D6, research R12; Part 3 §9.3): customers with at least
 * `flag.pattern_txn_threshold` transactions in a period, each with four measured figures and
 * no verdict. A person reads the figures and decides; nothing here suspends anyone or alerts.
 *
 *  - weight under: of the sold pieces that were weighed at IGI, how many came in under the
 *    stated weight, and by how much on average;
 *  - not paid: of the requests the person had accepted as a buyer, how many ended unpaid;
 *  - same counterparty: the other party the person completed the most orders with;
 *  - sent back: of the listings sent for review, how many were sent back for changes.
 *
 * Read in the staff scope; the caller has checked the permission and audits the read.
 */
final class ListPeopleToWatchAction
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{threshold: int, from: string, to: string, rows: list<array<string, mixed>>}
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $threshold = $this->settings->integer(SettingKey::FLAG_PATTERN_TXN_THRESHOLD);
        $end = $to->endOfDay();

        // One row per order a customer took part in, either side, within the period.
        $sides = DB::select(<<<'SQL'
            WITH sides AS (
              SELECT o.order_id, o.seller_id AS customer_id, o.buyer_id AS other_id, 'sold' AS role, o.state
                FROM "order" o WHERE o.accepted_at BETWEEN ? AND ?
              UNION ALL
              SELECT o.order_id, o.buyer_id, o.seller_id, 'bought', o.state
                FROM "order" o WHERE o.accepted_at BETWEEN ? AND ?
            )
            SELECT s.customer_id,
                   count(*) FILTER (WHERE s.role = 'sold')   AS sold,
                   count(*) FILTER (WHERE s.role = 'bought') AS bought,
                   count(*) FILTER (WHERE s.role = 'bought') AS accepted_as_buyer,
                   count(*) FILTER (WHERE s.role = 'bought' AND s.state = 'cancelled_buyer_nopay') AS not_paid
              FROM sides s
             GROUP BY s.customer_id
            HAVING count(*) >= ?
            SQL, [$from, $end, $from, $end, $threshold]);

        if ($sides === []) {
            return ['threshold' => $threshold, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => []];
        }

        $ids = array_map(fn ($r) => $r->customer_id, $sides);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $weights = DB::select(<<<SQL
            SELECT o.seller_id AS customer_id,
                   count(*) AS weighed,
                   count(*) FILTER (WHERE r.weight_diff_pct < 0) AS under,
                   COALESCE(avg(r.weight_diff_pct) FILTER (WHERE r.weight_diff_pct < 0), 0) AS average
              FROM "order" o
              JOIN inspection_result r ON r.order_id = o.order_id AND r.supersedes_id IS NULL
             WHERE o.accepted_at BETWEEN ? AND ? AND o.seller_id IN ($in)
             GROUP BY o.seller_id
            SQL, [$from, $end, ...$ids]);

        $partners = DB::select(<<<SQL
            WITH pairs AS (
              SELECT o.seller_id AS customer_id, o.buyer_id AS other_id FROM "order" o
               WHERE o.state = 'completed' AND o.accepted_at BETWEEN ? AND ?
              UNION ALL
              SELECT o.buyer_id, o.seller_id FROM "order" o
               WHERE o.state = 'completed' AND o.accepted_at BETWEEN ? AND ?
            ), counted AS (
              SELECT customer_id, other_id, count(*) AS n,
                     row_number() OVER (PARTITION BY customer_id ORDER BY count(*) DESC, other_id) AS rk
                FROM pairs WHERE customer_id IN ($in) GROUP BY customer_id, other_id
            )
            SELECT c.customer_id, c.n, p.display_ref, p.full_name
              FROM counted c JOIN customer p ON p.customer_id = c.other_id WHERE c.rk = 1
            SQL, [$from, $end, $from, $end, ...$ids]);

        $sentBack = DB::select(<<<SQL
            SELECT l.seller_id AS customer_id,
                   count(DISTINCT c.listing_id) FILTER (WHERE c.to_state = 'in_review') AS submitted,
                   count(DISTINCT c.listing_id) FILTER (WHERE c.to_state = 'changes_requested') AS sent_back
              FROM listing_state_change c JOIN listing l ON l.listing_id = c.listing_id
             WHERE c.changed_at BETWEEN ? AND ? AND l.seller_id IN ($in)
             GROUP BY l.seller_id
            SQL, [$from, $end, ...$ids]);

        $people = DB::table('customer')->whereIn('customer_id', $ids)->get(['customer_id', 'display_ref', 'full_name'])->keyBy('customer_id');
        $weights = collect($weights)->keyBy('customer_id');
        $partners = collect($partners)->keyBy('customer_id');
        $sentBack = collect($sentBack)->keyBy('customer_id');

        $rows = [];
        foreach ($sides as $s) {
            $total = (int) $s->sold + (int) $s->bought;
            $w = $weights->get($s->customer_id);
            $p = $partners->get($s->customer_id);
            $b = $sentBack->get($s->customer_id);

            $ratios = [
                'weight_under' => $w && (int) $w->weighed > 0 ? (int) $w->under / (int) $w->weighed : 0.0,
                'not_paid' => (int) $s->accepted_as_buyer > 0 ? (int) $s->not_paid / (int) $s->accepted_as_buyer : 0.0,
                'same_counterparty' => $p ? (int) $p->n / max(1, $total) : 0.0,
                'sent_back' => $b && (int) $b->submitted > 0 ? (int) $b->sent_back / (int) $b->submitted : 0.0,
            ];
            arsort($ratios);

            $person = $people->get($s->customer_id);
            $rows[] = [
                'customer_id' => $s->customer_id,
                'display_ref' => $person?->display_ref,
                'full_name' => $person?->full_name,
                'sold' => (int) $s->sold,
                'bought' => (int) $s->bought,
                'weight_under' => ['n' => $w ? (int) $w->under : 0, 'of' => $w ? (int) $w->weighed : 0, 'average_pct' => $w ? number_format((float) $w->average, 1, '.', '') : '0.0'],
                'not_paid' => ['n' => (int) $s->not_paid, 'of' => (int) $s->accepted_as_buyer],
                'same_counterparty' => $p ? ['count' => (int) $p->n, 'display_ref' => $p->display_ref, 'full_name' => $p->full_name] : null,
                'sent_back' => ['n' => $b ? (int) $b->sent_back : 0, 'of' => $b ? (int) $b->submitted : 0],
                'strongest' => array_key_first($ratios),
                'strongest_ratio' => round((float) reset($ratios), 4),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['strongest_ratio'], $b['sold'] + $b['bought']] <=> [$a['strongest_ratio'], $a['sold'] + $a['bought']]);

        return ['threshold' => $threshold, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows];
    }
}
