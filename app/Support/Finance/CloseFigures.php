<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The books of a Cairo day (spec 015 FR-013, FR-015, research R8): every
 * balance as the ledger stood at the cut-off — midnight Africa/Cairo at the
 * day's end, or now for a day that has not ended — plus the movements recorded
 * by hand with that statement date. The bank's cash is −SUM(bank) (spec 008
 * R15); the Dahab wallet is commission + spread (the statement's view=dahab).
 */
final class CloseFigures
{
    public static function cutOff(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Africa/Cairo')->addDay()->startOfDay();
    }

    public static function ended(string $date): bool
    {
        return CarbonImmutable::now('Africa/Cairo')->greaterThanOrEqualTo(self::cutOff($date));
    }

    /** @return array{bank: string, customer_available: string, customer_held: string, customer_liability: string, dahab_wallet: string, escrow: string, vat_payable: string, movements_in: string, movements_out: string} */
    public static function at(string $date): array
    {
        $cut = self::ended($date) ? self::cutOff($date) : null;

        $row = DB::selectOne("
            SELECT (-COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'bank'), 0))::numeric(18,4)::text                       AS bank,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'), 0)::numeric(18,4)::text                AS available,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'), 0)::numeric(18,4)::text                     AS held,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind IN ('dahab_commission', 'dahab_spread')), 0)::numeric(18,4)::text AS dahab,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'escrow'), 0)::numeric(18,4)::text                        AS escrow,
                   COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'vat_payable'), 0)::numeric(18,4)::text                   AS vat
            FROM ledger_posting p
            JOIN account a ON a.account_id = p.account_id
            JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
            WHERE (?::timestamptz IS NULL OR t.created_at < ?::timestamptz)
        ", [$cut, $cut]);

        $moved = DB::selectOne("
            SELECT COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0)::numeric(18,4)::text AS money_in,
                   (-COALESCE(SUM(amount) FILTER (WHERE amount < 0), 0))::numeric(18,4)::text AS money_out
            FROM bank_movement WHERE occurred_on = ? AND kind <> 'own_transfer'
        ", [$date]);

        return [
            'bank' => $row->bank,
            'customer_available' => $row->available,
            'customer_held' => $row->held,
            'customer_liability' => bcadd($row->available, $row->held, 4),
            'dahab_wallet' => $row->dahab,
            'escrow' => $row->escrow,
            'vat_payable' => $row->vat,
            'movements_in' => $moved->money_in,
            'movements_out' => $moved->money_out,
        ];
    }
}
