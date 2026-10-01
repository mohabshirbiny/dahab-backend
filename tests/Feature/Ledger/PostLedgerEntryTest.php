<?php

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerPosting;
use App\Models\LedgerTransaction;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 US1 (FR-005..FR-011, SC-001/SC-002). Posting has no HTTP endpoint
// in this core-only feature, so it is tested at the Action level against real
// PostgreSQL (temporary Principle V waiver, approved 2026-09-28).

/** Post inside a transaction and force the deferred checks, like a commit would. */
function post(LedgerEntry $entry): LedgerTransaction
{
    return DB::transaction(function () use ($entry) {
        $txn = app(PostLedgerEntryAction::class)->handle($entry);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $txn;
    });
}

function line(string $account, string $amount): LedgerLine
{
    return new LedgerLine($account, $amount);
}

function wallet(Customer $customer): array
{
    $row = DB::selectOne('SELECT available::numeric(18,4)::text AS available, held::numeric(18,4)::text AS held FROM customer_wallet WHERE customer_id = ?', [$customer->customer_id]);

    return [$row->available, $row->held];
}

function globalZero(): string
{
    return (string) DB::selectOne('SELECT must_be_zero::text AS z FROM ledger_global_zero')->z;
}

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->bank = Account::internal(AccountKind::BANK);
    $this->available = Ledger::available($this->customer);
    $this->held = Ledger::held($this->customer);
});

it('posts a top-up and a hold as balanced entries', function () {
    $topUp = post(new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-1000'), line($this->available, '1000')], actorCustomerId: $this->customer->customer_id));
    post(new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [line($this->available, '-400'), line($this->held, '400')], actorCustomerId: $this->customer->customer_id));

    expect(wallet($this->customer))->toBe(['600.0000', '400.0000'])
        ->and((string) DB::selectOne('SELECT bank_balance::text AS b FROM solvency_check')->b)->toBe('1000.0000')
        ->and(globalZero())->toBe('0.0000')
        ->and($topUp->postings()->count())->toBe(2)
        ->and($topUp->fresh()->event_kind)->toBe(LedgerEventKind::TOPUP);
});

it('commits with the caller and rolls back with the caller', function () {
    try {
        DB::transaction(function () {
            app(PostLedgerEntryAction::class)->handle(new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-50'), line($this->available, '50')], actorCustomerId: $this->customer->customer_id));
            throw new RuntimeException('caller failed');
        });
    } catch (RuntimeException) {
    }

    expect(LedgerTransaction::query()->count())->toBe(0)->and(LedgerPosting::query()->count())->toBe(0);
});

it('refuses an invalid entry before anything is written', function (string $case) {
    $c = $this->customer->customer_id;
    $build = match ($case) {
        'one line' => fn () => new LedgerEntry(LedgerEventKind::TOPUP, [line($this->available, '10')], actorCustomerId: $c),
        'a zero line' => fn () => line($this->available, '0'),
        'five decimal places' => fn () => line($this->available, '1.00001'),
        'a float-looking amount' => fn () => line($this->available, '1e3'),
        'a non-zero sum' => fn () => new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-10'), line($this->available, '9.9999')], actorCustomerId: $c),
        'no actor' => fn () => new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-10'), line($this->available, '10')]),
        'a memo over 1000' => fn () => new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-10'), line($this->available, '10')], actorCustomerId: $c, memo: str_repeat('x', 1001)),
        'an unknown account' => fn () => post(new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-10'), line('00000000-0000-0000-0000-000000000000', '10')], actorCustomerId: $c)),
    };

    expect($build)->toThrow(InvalidArgumentException::class);
    expect(LedgerTransaction::query()->count())->toBe(0);
})->with(['one line', 'a zero line', 'five decimal places', 'a float-looking amount', 'a non-zero sum', 'no actor', 'a memo over 1000', 'an unknown account']);

it('refuses to overdraw available or held', function (string $from, string $to) {
    Ledger::topUp($this->customer, '100');

    expect(fn () => post(new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [line($this->{$from}, '-100.0001'), line($this->{$to}, '100.0001')], actorCustomerId: $this->customer->customer_id)))
        ->toThrow(DomainApiException::class, 'not enough money');

    expect(wallet($this->customer))->toBe(['100.0000', '0.0000'])->and(globalZero())->toBe('0.0000');
})->with([
    'available' => ['available', 'held'],
    'held' => ['held', 'available'],
]);

it('nets two lines on the same account', function () {
    Ledger::topUp($this->customer, '100');

    post(new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [
        line($this->available, '-150'),
        line($this->available, '80'),
        line($this->held, '70'),
    ], actorCustomerId: $this->customer->customer_id));

    expect(wallet($this->customer))->toBe(['30.0000', '70.0000']);
});

it('must run inside a transaction', function () {
    DB::rollBack(); // leave RefreshDatabase's transaction for this one check

    try {
        expect(fn () => app(PostLedgerEntryAction::class)->handle(new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-1'), line($this->available, '1')], actorCustomerId: $this->customer->customer_id)))
            ->toThrow(LogicException::class, 'inside the caller');
    } finally {
        DB::beginTransaction();
    }
});

it('posts from a customer scope an entry that touches internal accounts', function () {
    DatabaseActor::push('customer', $this->customer->customer_id);

    try {
        post(new LedgerEntry(LedgerEventKind::TOPUP, [line($this->bank, '-25'), line($this->available, '25')], actorCustomerId: $this->customer->customer_id));
        // The customer still sees only their own line, and the deferred checks
        // (which read every line of the entry) passed at the forced commit.
        $visible = (int) DB::selectOne('SELECT count(*) AS n FROM ledger_posting')->n;
    } finally {
        DatabaseActor::pop();
    }

    expect($visible)->toBe(1)->and(wallet($this->customer))->toBe(['25.0000', '0.0000'])->and(DatabaseActor::scope())->toBe('maintenance');
});

it('keeps the references, the memo and the staff actor', function () {
    $staff = Staff::factory()->create();
    $order = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;

    $txn = post(new LedgerEntry(LedgerEventKind::COMPENSATION, [
        line(Account::internal(AccountKind::EXTERNAL_EQUITY), '-800'),
        line($this->available, '800'),
    ], actorStaffId: $staff->staff_id, memo: 'Wasted trip to IGI', listingId: $order))->fresh();

    expect($txn->staff_id)->toBe($staff->staff_id)
        ->and($txn->customer_id)->toBeNull()
        ->and($txn->memo)->toBe('Wasted trip to IGI')
        // listing_id: a reference without a foreign key (order_id has one since spec 011).
        ->and($txn->listing_id)->toBe($order);
});

it('refuses to edit a posted entry through the model', function () {
    $txn = Ledger::topUp($this->customer, '10');

    expect(fn () => $txn->update(['memo' => 'x']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $txn->delete())->toThrow(LogicException::class, 'append-only');
});
