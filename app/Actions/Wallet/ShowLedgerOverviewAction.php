<?php

namespace App\Actions\Wallet;

use Illuminate\Support\Facades\DB;

/**
 * The Overview's money figures (spec 008 FR-018): what customers hold, the
 * bank's cash, the safety figure (bank minus what is owed to customers) and
 * the whole-system check, which must read zero. All derived from the
 * ledger views; the bank's cash is -SUM(bank) (research R15).
 */
final class ShowLedgerOverviewAction
{
    /** @return array{available: string, held: string, held_on_orders: string, pending_withdrawals: string, total_owed: string, bank: string, headroom: string, system_total: string} */
    public function handle(): array
    {
        $row = DB::selectOne("
            SELECT
              (SELECT COALESCE(SUM(p.amount), 0) FROM ledger_posting p JOIN account a ON a.account_id = p.account_id WHERE a.kind = 'cust_available')::numeric(18,4)::text AS available,
              (SELECT COALESCE(SUM(p.amount), 0) FROM ledger_posting p JOIN account a ON a.account_id = p.account_id WHERE a.kind = 'cust_held')::numeric(18,4)::text      AS held,
              s.owed_to_customers::numeric(18,4)::text AS total_owed,
              s.bank_balance::numeric(18,4)::text      AS bank,
              s.headroom::numeric(18,4)::text          AS headroom,
              (SELECT must_be_zero FROM ledger_global_zero)::numeric(18,4)::text AS system_total
            FROM solvency_check s
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
