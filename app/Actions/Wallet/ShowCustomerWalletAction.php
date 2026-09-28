<?php

namespace App\Actions\Wallet;

use Illuminate\Support\Facades\DB;

/**
 * A customer's two figures and their total, derived from the ledger
 * (spec 008 FR-012/FR-013; Part 2 §8). Never a stored balance.
 *
 * Called in the customer's own scope (row-level security returns only their
 * accounts) or in the staff scope for the customer file.
 */
final class ShowCustomerWalletAction
{
    /** @return array{available: string, held: string, total: string, currency: string} */
    public function handle(string $customerId): array
    {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'), 0)::numeric(18,4)::text AS available,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0)::numeric(18,4)::text      AS held,
                   COALESCE(SUM(p.amount), 0)::numeric(18,4)::text                                          AS total
            FROM account a
            LEFT JOIN ledger_posting p ON p.account_id = a.account_id
            WHERE a.customer_id = ?
        ", [$customerId]);

        return [
            'available' => $row->available,
            'held' => $row->held,
            'total' => $row->total,
            'currency' => 'EGP',
        ];
    }
}
