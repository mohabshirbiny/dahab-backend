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
    /** @return array{available: string, held: string, held_on_orders: string, pending_withdrawals: string, total: string, currency: string} */
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

        // Spec 013 FR-017: withdrawals not yet released are held too; split them out.
        $pending = (string) DB::selectOne("
            SELECT COALESCE(SUM(amount), 0)::numeric(18,4)::text AS pending
            FROM withdrawal WHERE customer_id = ? AND state IN ('requested', 'under_review')
        ", [$customerId])->pending;

        return [
            'available' => $row->available,
            'held' => $row->held,
            'held_on_orders' => bcsub($row->held, $pending, 4),
            'pending_withdrawals' => $pending,
            'total' => $row->total,
            'currency' => 'EGP',
        ];
    }
}
