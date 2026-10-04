<?php

use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Ledger;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 data-model §1–§4: the new tables, their CHECKs, the append-only
// rows, the locked close, the deferred checks (DH009, DH011) and forced
// row-level security on wallet_adjustment.

/** Run SQL as maintenance and return the SQLSTATE it raised, or null (the DisputeSchemaTest technique). */
function financeSqlState(Closure $work): ?string
{
    try {
        DatabaseActor::elevate('maintenance', fn () => DB::transaction(function () use ($work) {
            $work();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }));
    } catch (QueryException|PDOException $e) {
        return $e->errorInfo[0] ?? (string) $e->getCode();
    } finally {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    return null;
}

/** A balanced entry written by hand (maintenance), returning its id. */
function financeEntry(LedgerEventKind $kind, array $lines, ?string $staffId): string
{
    return Ledger::postAt(now('Africa/Cairo')->format('Y-m-d H:i:s'), $kind, null, $lines, $staffId)->ledger_txn_id;
}

function closeRow(string $staffId, array $overrides = []): array
{
    return array_merge([
        'close_date' => '2026-10-01', 'bank_balance' => '100', 'books_bank' => '100', 'customer_available' => '60',
        'customer_held' => '40', 'customer_liability' => '100', 'dahab_wallet' => '0', 'escrow' => '0', 'vat_payable' => '0',
        'movements_in' => '0', 'movements_out' => '0', 'difference' => '0', 'is_locked' => false,
        'saved_by' => $staffId, 'saved_at' => now(),
    ], $overrides);
}

it('creates the tables, the forced row-level security on wallet_adjustment and none on the staff-only tables', function () {
    $row = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'wallet_adjustment'");
    expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue();
    foreach (['bank_movement', 'daily_close'] as $table) {
        expect(DB::selectOne('SELECT relrowsecurity FROM pg_class WHERE relname = ?', [$table])->relrowsecurity)->toBeFalse();
    }
});

it('lets compensation go without a dispute, but keeps the dispute–order and order–party pairs', function () {
    $customer = Customer::factory()->verified()->create();
    $staff = Orders::staff($this, SeedRole::FINANCE);
    $base = ['customer_id' => $customer->customer_id, 'amount' => '50', 'reason' => 'goodwill', 'note' => 'A goodwill payment.',
        'paid_by' => $staff->staff_id];

    // A matching entry: no order, no dispute.
    expect(financeSqlState(function () use ($base, $customer, $staff) {
        $txn = financeEntry(LedgerEventKind::COMPENSATION, [[Account::internal(AccountKind::EXTERNAL_EQUITY), '-50'], [Ledger::available($customer), '50']], $staff->staff_id);
        DB::table('compensation')->insert($base + ['ledger_txn_id' => $txn]);
    }))->toBeNull();

    // No entry → the deferred check.
    expect(financeSqlState(function () use ($base, $customer, $staff) {
        $txn = financeEntry(LedgerEventKind::TOPUP, [[Account::internal(AccountKind::BANK), '-50'], [Ledger::available($customer), '50']], $staff->staff_id);
        DB::table('compensation')->insert($base + ['ledger_txn_id' => $txn]);
    }))->toBe('DH009');

    // A party without an order; a dispute without an order.
    expect(financeSqlState(fn () => DB::table('compensation')->insert($base + ['party' => 'buyer', 'ledger_txn_id' => (string) Str::uuid()])))->toBe('23514');
});

it('ties a wallet adjustment to its own reversal entry, and keeps it append-only', function () {
    $customer = Customer::factory()->verified()->create();
    $ceo = Orders::staff($this, SeedRole::CEO);
    $row = fn (string $txn, array $o = []) => array_merge(['customer_id' => $customer->customer_id, 'direction' => 'credit', 'amount' => '75',
        'reason' => 'Correcting a wrong amount.', 'customer_status' => 'verified', 'adjusted_by' => $ceo->staff_id, 'ledger_txn_id' => $txn], $o);
    $other = Orders::staff($this, SeedRole::FINANCE)->staff_id;
    $credit = fn () => financeEntry(LedgerEventKind::REVERSAL, [[Account::internal(AccountKind::EXTERNAL_EQUITY), '-75'], [Ledger::available($customer), '75']], $ceo->staff_id);

    expect(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row($credit()))))->toBeNull()
        // The wrong direction, the wrong kind, another staff member.
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row($credit(), ['direction' => 'debit']))))->toBe('DH011')
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row(financeEntry(LedgerEventKind::COMPENSATION,
            [[Account::internal(AccountKind::EXTERNAL_EQUITY), '-75'], [Ledger::available($customer), '75']], $ceo->staff_id)))))->toBe('DH011')
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row($credit(), ['adjusted_by' => $other]))))->toBe('DH011')
        // CHECKs.
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row($credit(), ['amount' => '0']))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->insert($row($credit(), ['reason' => 'short']))))->toBe('23514');

    $id = DatabaseActor::elevate('maintenance', fn () => DB::table('wallet_adjustment')->value('adjustment_id'));
    expect(financeSqlState(fn () => DB::table('wallet_adjustment')->where('adjustment_id', $id)->update(['reason' => 'Something different now.'])))->not->toBeNull()
        ->and(financeSqlState(fn () => DB::table('wallet_adjustment')->where('adjustment_id', $id)->delete()))->not->toBeNull();
});

it('ties a bank movement to its entry unless it is an own-account transfer', function () {
    $finance = Orders::staff($this, SeedRole::FINANCE);
    $row = fn (array $o) => array_merge(['kind' => 'bank_charge', 'amount' => '-250', 'occurred_on' => '2026-10-01',
        'reason' => 'Monthly account fee', 'recorded_by' => $finance->staff_id], $o);
    $out = fn (string $amount) => financeEntry(LedgerEventKind::EXTERNAL_BANK_MOVEMENT,
        [[Account::internal(AccountKind::BANK), $amount], [Account::internal(AccountKind::EXTERNAL_EQUITY), bcmul($amount, '-1', 4)]], $finance->staff_id);

    expect(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['ledger_txn_id' => $out('250')]))))->toBeNull()
        // The bank moved the wrong way.
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['ledger_txn_id' => $out('-250')]))))->toBe('DH011')
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['kind' => 'own_transfer', 'amount' => '1000']))))->toBeNull()
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['kind' => 'own_transfer', 'ledger_txn_id' => $out('250')]))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row([]))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['kind' => 'own_transfer', 'amount' => '0']))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['kind' => 'own_transfer', 'proof_ref' => 'x']))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('bank_movement')->insert($row(['kind' => 'rent', 'ledger_txn_id' => $out('250')]))))->toBe('23514');

    $id = DatabaseActor::elevate('maintenance', fn () => DB::table('bank_movement')->value('movement_id'));
    expect(financeSqlState(fn () => DB::table('bank_movement')->where('movement_id', $id)->delete()))->not->toBeNull();
});

it('freezes a locked day and keeps the close CHECKs', function () {
    $finance = Orders::staff($this, SeedRole::FINANCE);

    expect(financeSqlState(fn () => DB::table('daily_close')->insert(closeRow($finance->staff_id, ['difference' => '5']))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('daily_close')->insert(closeRow($finance->staff_id, ['customer_liability' => '99']))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('daily_close')->insert(closeRow($finance->staff_id, ['is_locked' => true]))))->toBe('23514')
        ->and(financeSqlState(fn () => DB::table('daily_close')->insert(closeRow($finance->staff_id, ['is_locked' => true, 'closed_by' => $finance->staff_id, 'closed_at' => now(),
            'bank_balance' => '90', 'difference' => '-10']))))->toBe('23514');

    // Unlocked: may change; locked: never.
    expect(financeSqlState(fn () => DB::table('daily_close')->insert(closeRow($finance->staff_id))))->toBeNull()
        ->and(financeSqlState(fn () => DB::table('daily_close')->where('close_date', '2026-10-01')->update(['explanation' => 'Checked twice today.'])))->toBeNull()
        ->and(financeSqlState(fn () => DB::table('daily_close')->where('close_date', '2026-10-01')->update(['is_locked' => true, 'closed_by' => $finance->staff_id, 'closed_at' => now()])))->toBeNull()
        ->and(financeSqlState(fn () => DB::table('daily_close')->where('close_date', '2026-10-01')->update(['explanation' => 'Changed after the lock.'])))->not->toBeNull()
        ->and(financeSqlState(fn () => DB::table('daily_close')->where('close_date', '2026-10-01')->delete()))->not->toBeNull();
});

it('can be truncated, as the truncating concurrency tests do', function () {
    expect(fn () => DatabaseActor::elevate('maintenance', fn () => DB::statement('TRUNCATE wallet_adjustment, bank_movement, daily_close')))->not->toThrow(Throwable::class);
});
