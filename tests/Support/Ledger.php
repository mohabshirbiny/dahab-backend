<?php

namespace Tests\Support;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Support\Facades\DB;

/**
 * Test fixtures for the ledger (spec 008). Every entry is balanced and uses
 * the documented signs: money arriving is bank −X / customer +X (R15).
 */
final class Ledger
{
    public static function available(Customer $customer): string
    {
        return Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE);
    }

    public static function held(Customer $customer): string
    {
        return Account::forCustomerKind($customer->customer_id, AccountKind::CUST_HELD);
    }

    public static function topUp(Customer $customer, string $amount): LedgerTransaction
    {
        return self::post(LedgerEventKind::TOPUP, $customer, [
            [Account::internal(AccountKind::BANK), '-'.$amount],
            [self::available($customer), $amount],
        ]);
    }

    public static function hold(Customer $customer, string $amount): LedgerTransaction
    {
        return self::post(LedgerEventKind::DEPOSIT_HOLD, $customer, [
            [self::available($customer), '-'.$amount],
            [self::held($customer), $amount],
        ]);
    }

    public static function release(Customer $customer, string $amount): LedgerTransaction
    {
        return self::post(LedgerEventKind::DEPOSIT_RELEASE, $customer, [
            [self::held($customer), '-'.$amount],
            [self::available($customer), $amount],
        ]);
    }

    /**
     * A balanced entry at a chosen time (statement periods). The money
     * service always stamps `now()`, so this fixture writes the rows itself
     * in the maintenance scope; the deferred checks still run.
     *
     * @param  list<array{0: string, 1: string}>  $lines
     */
    public static function postAt(string $when, LedgerEventKind $kind, ?Customer $actor, array $lines, ?string $staffId = null, ?string $memo = null): LedgerTransaction
    {
        return DatabaseActor::elevate('maintenance', function () use ($when, $kind, $actor, $lines, $staffId, $memo) {
            return DB::transaction(function () use ($when, $kind, $actor, $lines, $staffId, $memo) {
                $id = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
                DB::insert(
                    "INSERT INTO ledger_transaction (ledger_txn_id, event_kind, customer_id, staff_id, memo, created_at) VALUES (?, ?::ledger_event_kind, ?, ?, ?, (?::timestamp AT TIME ZONE 'Africa/Cairo'))",
                    [$id, $kind->value, $actor?->customer_id, $staffId, $memo, $when],
                );
                foreach ($lines as [$account, $amount]) {
                    DB::insert('INSERT INTO ledger_posting (ledger_txn_id, account_id, amount) VALUES (?, ?, ?)', [$id, $account, $amount]);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');

                return LedgerTransaction::query()->findOrFail($id);
            });
        });
    }

    /**
     * Post a balanced entry through the money service (spec 008 T014) in its
     * own transaction, forcing the deferred checks as a commit would.
     *
     * @param  list<array{0: string, 1: string}>  $lines
     */
    public static function post(LedgerEventKind $kind, ?Customer $actor, array $lines, ?string $staffId = null, ?string $memo = null): LedgerTransaction
    {
        return DB::transaction(function () use ($kind, $actor, $lines, $staffId, $memo) {
            $txn = app(PostLedgerEntryAction::class)->handle(new LedgerEntry(
                $kind,
                array_map(fn (array $line) => new LedgerLine($line[0], $line[1]), $lines),
                actorCustomerId: $actor?->customer_id,
                actorStaffId: $staffId,
                memo: $memo,
            ));

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $txn;
        });
    }
}
