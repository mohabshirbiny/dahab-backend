<?php

namespace App\Actions\Wallet;

use App\Support\Overview\OverviewCache;
use Illuminate\Support\Facades\DB;

/**
 * The Overview's money figures (spec 008 FR-018): what customers hold, the
 * bank's cash, the safety figure (bank minus what is owed to customers) and
 * the whole-system check, which must read zero. The bank's cash is
 * -SUM(bank) (research R15). One pass over the postings, summed per account
 * first and then per kind: the same figures as the solvency_check and
 * ledger_global_zero views, which read the whole ledger five times. Reused
 * for a few seconds (OverviewCache); the route already requires wallet.view.
 */
final class ShowLedgerOverviewAction
{
    /** @return array{available: string, held: string, held_on_orders: string, pending_withdrawals: string, total_owed: string, bank: string, headroom: string, system_total: string} */
    public function handle(): array
    {
        return OverviewCache::section('wallets', fn () => $this->read());
    }

    /** @return array{available: string, held: string, held_on_orders: string, pending_withdrawals: string, total_owed: string, bank: string, headroom: string, system_total: string} */
    private function read(): array
    {
        $row = DB::selectOne("
            WITH per_account AS (
              SELECT account_id, SUM(amount) AS amount FROM ledger_posting GROUP BY account_id
            ), totals AS (
              SELECT COALESCE(SUM(b.amount) FILTER (WHERE a.kind = 'cust_available'), 0) AS available,
                     COALESCE(SUM(b.amount) FILTER (WHERE a.kind = 'cust_held'), 0)      AS held,
                     -COALESCE(SUM(b.amount) FILTER (WHERE a.kind = 'bank'), 0)          AS bank,
                     COALESCE(SUM(b.amount), 0)                                          AS system_total
              FROM per_account b LEFT JOIN account a ON a.account_id = b.account_id
            )
            SELECT available::numeric(18,4)::text            AS available,
                   held::numeric(18,4)::text                 AS held,
                   (available + held)::numeric(18,4)::text   AS total_owed,
                   bank::numeric(18,4)::text                 AS bank,
                   (bank - (available + held))::numeric(18,4)::text AS headroom,
                   system_total::numeric(18,4)::text         AS system_total
            FROM totals
        ");

        // Spec 013 FR-017: withdrawals not yet released sit in held too; split them out.
        $pending = (string) DB::selectOne("
            SELECT COALESCE(SUM(amount), 0)::numeric(18,4)::text AS pending
            FROM withdrawal WHERE state IN ('requested', 'under_review')
        ")->pending;

        $figures = (array) $row;

        return array_slice($figures, 0, 2, true)
            + ['held_on_orders' => bcsub($figures['held'], $pending, 4), 'pending_withdrawals' => $pending]
            + array_slice($figures, 2, null, true);
    }
}
