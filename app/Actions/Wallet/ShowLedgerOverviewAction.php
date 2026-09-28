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
    /** @return array{available: string, held: string, total_owed: string, bank: string, headroom: string, system_total: string} */
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

        return (array) $row;
    }
}
