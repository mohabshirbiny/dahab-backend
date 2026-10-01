<?php

use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 008 US1/US2, FR-001..FR-011: what the database itself guarantees,
// whatever the application does (docs/Database schema/03_schema_ledger.sql).

/** Insert one raw entry (no money service) and return its id. */
function rawEntry(array $lines, ?string $staffId = null, ?string $customerId = null, string $kind = 'topup'): string
{
    $txn = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
    DB::insert('INSERT INTO ledger_transaction (ledger_txn_id, event_kind, staff_id, customer_id) VALUES (?, ?::ledger_event_kind, ?, ?)', [$txn, $kind, $staffId, $customerId]);
    foreach ($lines as [$account, $amount]) {
        DB::insert('INSERT INTO ledger_posting (ledger_txn_id, account_id, amount) VALUES (?, ?, ?)', [$txn, $account, $amount]);
    }

    return $txn;
}

function internalAccount(string $kind): string
{
    return (string) DB::table('account')->where('kind', $kind)->whereNull('customer_id')->value('account_id');
}

function customerAccount(Customer $customer, string $kind): string
{
    return (string) DB::table('account')->where('customer_id', $customer->customer_id)->where('kind', $kind)->value('account_id');
}

/**
 * Deferred constraint triggers fire at COMMIT. RefreshDatabase wraps each
 * test in a transaction, so SET CONSTRAINTS ALL IMMEDIATE makes them fire now.
 */
function checkNow(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function sqlState(Closure $work): ?string
{
    try {
        DB::transaction(function () use ($work) {
            $work();
            checkNow();
        });
    } catch (QueryException $e) {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('defines exactly the documented account and event kinds', function () {
    $values = fn (string $type) => collect(DB::select("SELECT unnest(enum_range(NULL::{$type}))::text AS v"))->pluck('v')->all();

    expect($values('account_kind'))->toBe([
        'cust_available', 'cust_held', 'escrow', 'dahab_commission', 'dahab_spread', 'vat_payable', 'bank', 'external_equity',
    ])->and($values('ledger_event_kind'))->toBe([
        'topup', 'deposit_hold', 'deposit_release', 'deposit_forfeit', 'settlement_seller', 'first_sale_payout',
        'commission', 'spread', 'vat', 'balance_payment', 'withdrawal', 'compensation', 'external_bank_movement',
        'weight_adjustment', 'reversal',
    ]);
});

it('ties customer kinds to an owner and internal kinds to none', function () {
    $customer = Customer::factory()->create();

    expect(sqlState(fn () => DB::insert("INSERT INTO account (kind, customer_id) VALUES ('bank', ?)", [$customer->customer_id])))->toBe('23514')
        ->and(sqlState(fn () => DB::insert("INSERT INTO account (kind) VALUES ('cust_available')")))->toBe('23514');
});

it('allows only one account of each internal kind', function () {
    expect(sqlState(fn () => DB::insert("INSERT INTO account (kind) VALUES ('escrow')")))->toBe('23505');
});

it('refuses updates and deletes of ledger rows', function () {
    $customer = Customer::factory()->create();
    $txn = rawEntry([[internalAccount('bank'), '-10'], [customerAccount($customer, 'cust_available'), '10']], customerId: $customer->customer_id);
    checkNow();

    foreach ([
        fn () => DB::update('UPDATE ledger_posting SET amount = amount WHERE ledger_txn_id = ?', [$txn]),
        fn () => DB::delete('DELETE FROM ledger_posting WHERE ledger_txn_id = ?', [$txn]),
        fn () => DB::update("UPDATE ledger_transaction SET memo = 'x' WHERE ledger_txn_id = ?", [$txn]),
        fn () => DB::delete('DELETE FROM ledger_transaction WHERE ledger_txn_id = ?', [$txn]),
    ] as $mutation) {
        // Two layers: there is no UPDATE/DELETE RLS policy (the statement
        // touches 0 rows), and the append-only trigger raises for any role
        // that bypasses RLS. Either way nothing changes.
        try {
            expect(DB::transaction($mutation))->toBe(0);
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('append-only');
        }
    }

    expect(DB::table('ledger_posting')->where('ledger_txn_id', $txn)->count())->toBe(2)
        ->and(DB::table('ledger_transaction')->where('ledger_txn_id', $txn)->value('memo'))->toBeNull();

    // The trigger itself, for a role that sees the rows (policy bypassed via a
    // temporary permissive policy inside this test's transaction).
    DB::statement('CREATE POLICY zz_probe ON ledger_posting FOR UPDATE USING (true)');
    expect(fn () => DB::transaction(fn () => DB::update('UPDATE ledger_posting SET amount = amount WHERE ledger_txn_id = ?', [$txn])))
        ->toThrow(QueryException::class, 'append-only');
});

it('refuses an entry without an actor and a zero line', function () {
    $customer = Customer::factory()->create();

    expect(sqlState(fn () => rawEntry([])))->toBe('23514')
        ->and(sqlState(fn () => rawEntry([[customerAccount($customer, 'cust_available'), '0']], customerId: $customer->customer_id)))->toBe('23514');
});

it('refuses an unbalanced entry with DH002', function () {
    $customer = Customer::factory()->create();

    expect(sqlState(fn () => rawEntry([
        [internalAccount('bank'), '-10'],
        [customerAccount($customer, 'cust_available'), '9.9999'],
    ], customerId: $customer->customer_id)))->toBe('DH002');
});

it('refuses taking a customer account below zero with DH001', function () {
    $customer = Customer::factory()->create();

    expect(sqlState(fn () => rawEntry([
        [customerAccount($customer, 'cust_available'), '-1'],
        [customerAccount($customer, 'cust_held'), '1'],
    ], customerId: $customer->customer_id, kind: 'deposit_hold')))->toBe('DH001');
});

it('allows an entry to be reversed only once', function () {
    $customer = Customer::factory()->create();
    $staff = Staff::factory()->create();
    $bank = internalAccount('bank');
    $available = customerAccount($customer, 'cust_available');
    $original = rawEntry([[$bank, '-10'], [$available, '10']], customerId: $customer->customer_id);
    checkNow();

    $reverse = function () use ($original, $staff, $bank, $available) {
        $txn = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
        DB::insert("INSERT INTO ledger_transaction (ledger_txn_id, event_kind, staff_id, reverses_txn_id) VALUES (?, 'reversal', ?, ?)", [$txn, $staff->staff_id, $original]);
        DB::insert('INSERT INTO ledger_posting (ledger_txn_id, account_id, amount) VALUES (?, ?, 10), (?, ?, -10)', [$txn, $bank, $txn, $available]);
    };

    expect(sqlState($reverse))->toBeNull();

    // The first reversal left 0 available; top up again so only the index can refuse.
    rawEntry([[$bank, '-10'], [$available, '10']], customerId: $customer->customer_id);
    checkNow();

    expect(sqlState($reverse))->toBe('23505');
});

it('derives balances through the documented views', function () {
    $customer = Customer::factory()->create();
    rawEntry([[internalAccount('bank'), '-1000'], [customerAccount($customer, 'cust_available'), '1000']], customerId: $customer->customer_id);
    rawEntry([[customerAccount($customer, 'cust_available'), '-400'], [customerAccount($customer, 'cust_held'), '400']], customerId: $customer->customer_id, kind: 'deposit_hold');
    checkNow();

    $wallet = DB::selectOne('SELECT available, held FROM customer_wallet WHERE customer_id = ?', [$customer->customer_id]);
    $solvency = DB::selectOne('SELECT bank_balance, owed_to_customers, headroom FROM solvency_check');

    expect((string) $wallet->available)->toBe('600.0000')
        ->and((string) $wallet->held)->toBe('400.0000')
        // Research R15: the bank's cash is -SUM(bank lines).
        ->and((string) $solvency->bank_balance)->toBe('1000.0000')
        ->and((string) $solvency->owed_to_customers)->toBe('1000.0000')
        ->and((string) $solvency->headroom)->toBe('0.0000')
        ->and((string) DB::selectOne('SELECT must_be_zero FROM ledger_global_zero')->must_be_zero)->toBe('0.0000');
});

it('reads zero on an empty ledger', function () {
    expect((string) DB::selectOne('SELECT must_be_zero FROM ledger_global_zero')->must_be_zero)->toBe('0');
});

it('refuses deleting a customer who has ledger lines', function () {
    $customer = Customer::factory()->create();
    rawEntry([[internalAccount('bank'), '-10'], [customerAccount($customer, 'cust_available'), '10']], customerId: $customer->customer_id);
    checkNow();

    expect(sqlState(fn () => DB::delete('DELETE FROM customer WHERE customer_id = ?', [$customer->customer_id])))->toBe('23503');
});

it('rolls back without leaving a ledger object behind', function () {
    // Buy requests (spec 011) and top-ups (spec 009) reference the ledger, so they roll back first.
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_04_000010_create_buy_requests.php', '--force' => true]);
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_02_000010_create_topups.php', '--force' => true]);
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_01_000010_create_ledger.php', '--force' => true]);

    $left = DB::selectOne("
        SELECT (SELECT count(*) FROM pg_class WHERE relname IN ('account','ledger_transaction','ledger_posting','account_balance','customer_wallet','solvency_check','ledger_global_zero'))
             + (SELECT count(*) FROM pg_type WHERE typname IN ('account_kind','ledger_event_kind'))
             + (SELECT count(*) FROM pg_proc WHERE proname IN ('block_mutation','assert_txn_balanced','assert_customer_account_nonneg','create_customer_accounts')) AS n
    ")->n;

    Artisan::call('migrate', ['--force' => true]);

    expect((int) $left)->toBe(0);
});
