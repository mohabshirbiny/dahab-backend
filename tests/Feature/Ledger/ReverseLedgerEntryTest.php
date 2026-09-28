<?php

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\Ledger\ReverseLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\Staff;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 FR-010 and the reversal edge cases.

function reverse(string $txnId, string $staffId, string $reason = 'Posted to the wrong wallet'): LedgerTransaction
{
    return DB::transaction(function () use ($txnId, $staffId, $reason) {
        $txn = app(ReverseLedgerEntryAction::class)->handle($txnId, $staffId, $reason);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $txn;
    });
}

function available(Customer $customer): string
{
    return (string) DB::selectOne('SELECT available::numeric(18,4)::text AS a FROM customer_wallet WHERE customer_id = ?', [$customer->customer_id])->a;
}

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->staff = Staff::factory()->create();
});

it('negates every line in a new entry that points to the original', function () {
    $original = Ledger::topUp($this->customer, '1000');

    $reversal = reverse($original->ledger_txn_id, $this->staff->staff_id)->fresh('postings');

    expect($reversal->event_kind)->toBe(LedgerEventKind::REVERSAL)
        ->and($reversal->reverses_txn_id)->toBe($original->ledger_txn_id)
        ->and($reversal->memo)->toBe('Posted to the wrong wallet')
        ->and($reversal->staff_id)->toBe($this->staff->staff_id)
        ->and($reversal->customer_id)->toBeNull()
        ->and($reversal->postings->pluck('amount')->all())->toBe(['1000.0000', '-1000.0000'])
        ->and($original->fresh('postings')->postings->pluck('amount')->all())->toBe(['-1000.0000', '1000.0000'])
        ->and(available($this->customer))->toBe('0.0000');
});

it('reverses an entry only once', function () {
    $original = Ledger::topUp($this->customer, '10');
    reverse($original->ledger_txn_id, $this->staff->staff_id);
    Ledger::topUp($this->customer, '10');

    expect(fn () => reverse($original->ledger_txn_id, $this->staff->staff_id))
        ->toThrow(DomainApiException::class, 'already been reversed');
});

it('allows reversing a reversal', function () {
    $original = Ledger::topUp($this->customer, '10');
    $reversal = reverse($original->ledger_txn_id, $this->staff->staff_id);

    $again = reverse($reversal->ledger_txn_id, $this->staff->staff_id, 'The first correction was wrong');

    expect($again->reverses_txn_id)->toBe($reversal->ledger_txn_id)->and(available($this->customer))->toBe('10.0000');
});

it('refuses a reversal that would overdraw the customer', function () {
    $original = Ledger::topUp($this->customer, '100');
    Ledger::hold($this->customer, '60');

    expect(fn () => reverse($original->ledger_txn_id, $this->staff->staff_id))
        ->toThrow(DomainApiException::class, 'not enough money')
        ->and(LedgerTransaction::query()->where('reverses_txn_id', $original->ledger_txn_id)->exists())->toBeFalse();
});

it('requires a reason of 1 to 1000 characters', function (string $reason) {
    $original = Ledger::topUp($this->customer, '10');

    expect(fn () => reverse($original->ledger_txn_id, $this->staff->staff_id, $reason))->toThrow(InvalidArgumentException::class);
})->with(['empty' => '', 'blank' => '   ', 'too long' => str_repeat('x', 1001)]);

it('copies the references of the original', function () {
    $order = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
    $original = DB::transaction(fn () => app(PostLedgerEntryAction::class)->handle(new LedgerEntry(
        LedgerEventKind::TOPUP,
        [new LedgerLine(Account::internal(AccountKind::BANK), '-5'), new LedgerLine(Ledger::available($this->customer), '5')],
        actorCustomerId: $this->customer->customer_id,
        orderId: $order,
    )));

    expect(reverse($original->ledger_txn_id, $this->staff->staff_id)->order_id)->toBe($order);
});
