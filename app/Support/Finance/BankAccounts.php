<?php

namespace App\Support\Finance;

use App\Models\ReceivingAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dahab's money split by account (owner notes of 2026-10-29,
 * `docs/features/bank-per-account.md`). The ledger keeps one bank account;
 * each of its postings belongs to the receiving account its source names — a
 * credited top-up the account it reached, a released withdrawal the account it
 * was paid from, a bank movement its own — and a reversal to the account of
 * the entry it reverses. An own-account transfer (a record, no ledger entry)
 * moves cash from one account (or from "not assigned") to another, on its
 * statement date. Money whose
 * source names no account — everything before this change — is "not assigned".
 *
 * Cash is −SUM(bank) (spec 008 R15), so the accounts and "not assigned" always
 * add up to the books' bank cash.
 */
final class BankAccounts
{
    /** The key of the cash no account was named for. */
    public const NOT_ASSIGNED = 'none';

    /**
     * Each account's cash as the books stood before `$cut` (now when null),
     * keyed by receiving account id, plus NOT_ASSIGNED; accounts with no money are absent.
     *
     * @return array<int|string, string>
     */
    public static function booksAt(?CarbonImmutable $cut): array
    {
        // An own-account transfer posts nothing and counts from its statement date, so the first close
        // after banks were named can assign the older money (the cut-off's Cairo date is the next day).
        $day = $cut?->setTimezone('Africa/Cairo')->toDateString();
        $rows = DB::select('
            WITH src AS (
                SELECT ledger_txn_id AS txn, receiving_account_id AS acc FROM topup WHERE ledger_txn_id IS NOT NULL
                UNION ALL SELECT release_txn_id, paid_from_account_id FROM withdrawal WHERE release_txn_id IS NOT NULL
                UNION ALL SELECT ledger_txn_id, receiving_account_id FROM bank_movement WHERE ledger_txn_id IS NOT NULL
            ), moves AS (
                SELECT COALESCE(s.acc, r.acc) AS acc, -p.amount AS cash
                FROM ledger_posting p
                JOIN account a ON a.account_id = p.account_id AND a.kind = \'bank\'
                JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
                LEFT JOIN src s ON s.txn = t.ledger_txn_id
                LEFT JOIN src r ON r.txn = t.reverses_txn_id
                WHERE (?::timestamptz IS NULL OR t.created_at < ?::timestamptz)
                UNION ALL
                SELECT receiving_account_id, -ABS(amount) FROM bank_movement
                WHERE kind = \'own_transfer\' AND to_receiving_account_id IS NOT NULL AND (?::date IS NULL OR occurred_on < ?::date)
                UNION ALL
                SELECT to_receiving_account_id, ABS(amount) FROM bank_movement
                WHERE kind = \'own_transfer\' AND to_receiving_account_id IS NOT NULL AND (?::date IS NULL OR occurred_on < ?::date)
            )
            SELECT acc, SUM(cash)::numeric(18,4)::text AS cash FROM moves GROUP BY acc
        ', [$cut, $cut, $day, $day, $day, $day]);

        $out = [];
        foreach ($rows as $r) {
            if (bccomp($r->cash, '0', 4) !== 0) {
                $out[$r->acc === null ? self::NOT_ASSIGNED : (int) $r->acc] = $r->cash;
            }
        }

        return $out;
    }

    /**
     * The accounts a close asks a balance for: every active one and every one
     * that still holds money, in display order, each with its books.
     *
     * @param  array<int|string, string>  $books
     * @return list<array{receiving_account_id: int, label: string, bank_name: ?string, method: string, is_active: bool, books: string}>
     */
    public static function forClose(array $books): array
    {
        return ReceivingAccount::query()
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('receiving_account_id', array_values(array_filter(array_keys($books), 'is_int'))))
            ->displayOrder()->get()
            ->map(fn (ReceivingAccount $a) => [
                'receiving_account_id' => $a->receiving_account_id,
                'label' => $a->label,
                'bank_name' => $a->bank_name,
                'method' => $a->method->value,
                'is_active' => $a->is_active,
                'books' => $books[$a->receiving_account_id] ?? '0.0000',
            ])->values()->all();
    }

    /**
     * The receiving account each of these ledger entries belongs to (a
     * reversal: the entry it reverses), keyed by ledger txn id.
     *
     * @param  list<string>  $txnIds
     * @return array<string, int>
     */
    public static function ofEntries(array $txnIds): array
    {
        if ($txnIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($txnIds), '?::uuid'));
        $rows = DB::select("
            WITH src AS (
                SELECT ledger_txn_id AS txn, receiving_account_id AS acc FROM topup WHERE ledger_txn_id IS NOT NULL
                UNION ALL SELECT release_txn_id, paid_from_account_id FROM withdrawal WHERE release_txn_id IS NOT NULL
                UNION ALL SELECT ledger_txn_id, receiving_account_id FROM bank_movement WHERE ledger_txn_id IS NOT NULL
            )
            SELECT t.ledger_txn_id AS txn, COALESCE(s.acc, r.acc) AS acc
            FROM ledger_transaction t
            LEFT JOIN src s ON s.txn = t.ledger_txn_id
            LEFT JOIN src r ON r.txn = t.reverses_txn_id
            WHERE t.ledger_txn_id IN ({$marks})
        ", $txnIds);

        $out = [];
        foreach ($rows as $r) {
            if ($r->acc !== null) {
                $out[$r->txn] = (int) $r->acc;
            }
        }

        return $out;
    }
}
