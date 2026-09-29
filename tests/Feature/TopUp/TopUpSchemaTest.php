<?php

use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 009 data-model: what the database itself guarantees for top-ups,
// whatever the application does (docs/Database schema/03_schema_ledger.sql,
// section "TOP-UPS").

/** Run one statement in a savepoint and return its SQLSTATE, or null when it succeeds. */
function topUpSqlState(Closure $work): ?string
{
    try {
        DB::transaction($work);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

function topUpAccount(string $method = 'instapay', ?string $staffId = null): int
{
    $staffId ??= Staff::factory()->create()->staff_id;
    $details = match ($method) {
        'bank_transfer' => ['bank_name' => 'Test Bank', 'account_holder' => 'Dahab Test', 'account_number' => '0000 0000 0000'],
        'instapay' => ['instapay_address' => 'dahab.test@instapay'],
        'vodafone_cash' => ['wallet_number' => '01000000000'],
    };

    return (int) DB::table('receiving_account')->insertGetId(array_merge([
        'method' => $method, 'label' => 'Test '.$method, 'updated_by' => $staffId,
    ], $details), 'receiving_account_id');
}

/** @param  array<string, mixed>  $overrides */
function topUpRow(Customer $customer, array $overrides = []): string
{
    $id = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
    DB::table('topup')->insert(array_merge([
        'topup_id' => $id,
        'customer_id' => $customer->customer_id,
        'origin' => 'notice',
        'method' => 'instapay',
        'reference' => 'DAHAB-'.$customer->display_ref,
        'claimed_amount' => '100.00',
    ], $overrides));

    return $id;
}

/** @return array<string, mixed> the columns of a valid credit */
function topUpCreditColumns(Customer $customer, int $account, string $staffId, string $amount = '100.00'): array
{
    return [
        'status' => 'credited',
        'credited_amount' => $amount,
        'receiving_account_id' => $account,
        'credited_by' => $staffId,
        'credited_at' => now(),
        'ledger_txn_id' => Ledger::topUp($customer, $amount)->ledger_txn_id,
    ];
}

beforeEach(function () {
    $this->customer = Customer::factory()->verified()->create();
    $this->staff = Staff::factory()->create();
    $this->account = topUpAccount('instapay', $this->staff->staff_id);
});

it('defines the documented top-up types', function () {
    $values = fn (string $type) => collect(DB::select("SELECT unnest(enum_range(NULL::{$type}))::text AS v"))->pluck('v')->all();

    expect($values('topup_method'))->toBe(['bank_transfer', 'instapay', 'vodafone_cash'])
        ->and($values('topup_origin'))->toBe(['notice', 'by_hand'])
        ->and($values('topup_status'))->toBe(['pending', 'on_hold', 'credited', 'rejected', 'cancelled'])
        ->and($values('topup_reject_reason'))->toBe(['money_not_received', 'duplicate_notice', 'sender_not_accepted', 'other']);
});

it('accepts a valid pending notice and numbers it', function () {
    $id = topUpRow($this->customer);

    expect(DB::table('topup')->where('topup_id', $id)->value('status'))->toBe('pending')
        ->and((int) DB::table('topup')->where('topup_id', $id)->value('topup_no'))->toBeGreaterThan(0);
});

it('refuses amounts that are not positive with at most 2 decimals', function () {
    expect(topUpSqlState(fn () => topUpRow($this->customer, ['claimed_amount' => '10.123'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['claimed_amount' => '0'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['claimed_amount' => '-5'])))->toBe('23514');
});

it('requires the claimed amount on a notice and forbids it on a hand credit', function () {
    expect(topUpSqlState(fn () => topUpRow($this->customer, ['claimed_amount' => null])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, array_merge(
            ['origin' => 'by_hand', 'credit_note' => 'by hand'],
            topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id),
        ))))->toBe('23514');
});

it('refuses a hand credit that is not credited or has no note', function () {
    expect(topUpSqlState(fn () => topUpRow($this->customer, ['origin' => 'by_hand', 'claimed_amount' => null, 'credit_note' => 'x'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, array_merge(
            ['origin' => 'by_hand', 'claimed_amount' => null],
            topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id),
        ))))->toBe('23514');
});

it('refuses a credited row without its ledger entry', function () {
    $columns = topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id);
    $columns['ledger_txn_id'] = null;

    expect(topUpSqlState(fn () => topUpRow($this->customer, $columns)))->toBe('23514');
});

it('refuses a different credited amount without a note', function () {
    $columns = topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id, '99.00');

    expect(topUpSqlState(fn () => topUpRow($this->customer, $columns)))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, array_merge($columns, [
            'credit_note' => 'InstaPay fee taken', 'ledger_txn_id' => Ledger::topUp($this->customer, '99.00')->ledger_txn_id,
        ]))))->toBeNull();
});

it('credits a ledger entry at most once', function () {
    $columns = topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id);
    topUpRow($this->customer, $columns);

    expect(topUpSqlState(fn () => topUpRow($this->customer, $columns)))->toBe('23505');
});

it('requires the reject, hold and cancel columns of their status', function () {
    expect(topUpSqlState(fn () => topUpRow($this->customer, ['status' => 'rejected', 'reject_reason' => 'other'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['status' => 'on_hold'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['status' => 'cancelled'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['cancelled_at' => now()])))->toBe('23514');
});

it('requires a receipt reference and its type together', function () {
    expect(topUpSqlState(fn () => topUpRow($this->customer, ['receipt_ref' => 'topup-receipts/x.enc'])))->toBe('23514')
        ->and(topUpSqlState(fn () => topUpRow($this->customer, ['receipt_ref' => 'topup-receipts/x.enc', 'receipt_mime' => 'image/png'])))->toBeNull();
});

it('freezes credited, rejected and cancelled rows', function () {
    $credited = topUpRow($this->customer, topUpCreditColumns($this->customer, $this->account, $this->staff->staff_id));
    $rejected = topUpRow($this->customer, [
        'status' => 'rejected', 'reject_reason' => 'money_not_received', 'reject_note' => 'n',
        'rejected_by' => $this->staff->staff_id, 'rejected_at' => now(),
    ]);
    $cancelled = topUpRow($this->customer, ['status' => 'cancelled', 'cancelled_at' => now()]);

    foreach ([$credited, $rejected, $cancelled] as $id) {
        expect(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['updated_at' => now()])))->toBe('DH003');
    }
});

it('never changes the identity columns of a row', function () {
    $id = topUpRow($this->customer);
    $other = Customer::factory()->verified()->create();

    expect(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['claimed_amount' => '200.00'])))->toBe('DH003')
        ->and(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['customer_id' => $other->customer_id])))->toBe('DH003')
        ->and(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['reference' => 'DAHAB-X'])))->toBe('DH003')
        ->and(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['notice_fee_percent' => '2.000'])))->toBe('DH003')
        ->and(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->update(['hold_note' => 'n', 'held_by' => $this->staff->staff_id, 'held_at' => now(), 'status' => 'on_hold'])))->toBeNull();
});

it('never deletes a top-up or a receiving account', function () {
    $id = topUpRow($this->customer);

    expect(topUpSqlState(fn () => DB::table('topup')->where('topup_id', $id)->delete()))->toBe('DH003')
        ->and(topUpSqlState(fn () => DB::table('receiving_account')->where('receiving_account_id', $this->account)->delete()))->toBe('DH003');
});

it('requires each method to carry exactly its own details', function () {
    $base = ['label' => 'x', 'updated_by' => $this->staff->staff_id];
    $insert = fn (array $row) => topUpSqlState(fn () => DB::table('receiving_account')->insert(array_merge($base, $row)));

    expect($insert(['method' => 'bank_transfer', 'bank_name' => 'B', 'account_holder' => 'H']))->toBe('23514')
        ->and($insert(['method' => 'vodafone_cash', 'wallet_number' => '12345']))->toBe('23514')
        ->and($insert(['method' => 'instapay', 'instapay_address' => 'a@instapay', 'wallet_number' => '01000000000']))->toBe('23514')
        ->and($insert(['method' => 'bank_transfer', 'bank_name' => 'B', 'account_holder' => 'H', 'account_number' => '1234 5678', 'iban' => 'EG12']))->toBe('23514')
        ->and($insert(['method' => 'bank_transfer', 'bank_name' => 'B', 'account_holder' => 'H', 'account_number' => '1234 5678']))->toBeNull();
});
