<?php

use App\Enums\AccountKind;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 008 US2 (FR-001..FR-003, SC-003). Registration itself is covered in
// tests/Feature/Auth/Customer/RegisterTest.php.

function accountKinds(string $customerId): array
{
    return DB::table('account')->where('customer_id', $customerId)->orderBy('kind')->pluck('kind')->all();
}

function provisionAccounts(): void
{
    (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
}

it('creates both accounts for any new customer, in the same transaction', function () {
    $customer = Customer::factory()->create();

    expect(accountKinds($customer->customer_id))->toBe(['cust_available', 'cust_held']);

    try {
        DB::transaction(function () {
            Customer::factory()->create(['phone' => '+201099999999']);
            throw new RuntimeException('registration failed after the insert');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('customer')->where('phone', '+201099999999')->exists())->toBeFalse()
        ->and(DB::table('account')->whereNotNull('customer_id')->count())->toBe(2);
});

it('backfills customers without accounts, and adds nothing when run again', function () {
    $customer = Customer::factory()->create();

    // A customer from before the ledger: no accounts. Accounts have no DELETE
    // policy, so simulate it with the trigger disabled for one insert.
    DB::statement('ALTER TABLE customer DISABLE TRIGGER trg_customer_accounts');
    $legacy = Customer::factory()->create();
    DB::statement('ALTER TABLE customer ENABLE TRIGGER trg_customer_accounts');
    expect(accountKinds($legacy->customer_id))->toBe([]);

    provisionAccounts();
    provisionAccounts();

    expect(accountKinds($legacy->customer_id))->toBe(['cust_available', 'cust_held'])
        ->and(accountKinds($customer->customer_id))->toBe(['cust_available', 'cust_held'])
        ->and(DB::table('account')->count())->toBe(4 + count(AccountKind::internal()));
});

it('has exactly one owner-less account of each internal kind', function () {
    provisionAccounts();

    $internal = DB::table('account')->whereNull('customer_id')->pluck('kind')->sort()->values()->all();
    $expected = collect(AccountKind::internal())->map->value->sort()->values()->all();

    expect($internal)->toBe($expected)
        ->and(DB::table('account')->whereNull('customer_id')->whereIn('kind', ['cust_available', 'cust_held'])->exists())->toBeFalse();
});

it('provisions under forced RLS even with no scope bound', function () {
    DatabaseActor::push('');

    try {
        provisionAccounts();
    } finally {
        DatabaseActor::pop();
    }

    expect(DB::table('account')->whereNull('customer_id')->count())->toBe(6);
});
