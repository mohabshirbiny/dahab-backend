<?php

use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 FR-014 / SC-005, research R3: customers read only their own
// ledger rows, nobody writes from a customer scope, and the money service's
// `ledger` scope sees the ledger and nothing else.

function countIn(string $scope, ?string $customerId, string $sql): int
{
    DatabaseActor::push($scope, $customerId);

    try {
        return (int) DB::selectOne($sql)->n;
    } finally {
        DatabaseActor::pop();
    }
}

beforeEach(function () {
    $this->a = Customer::factory()->create();
    $this->b = Customer::factory()->create();
    Ledger::topUp($this->a, '100');
    Ledger::topUp($this->b, '250');
    Ledger::hold($this->b, '50');
});

it('shows a customer only their own accounts, entries and lines', function () {
    $a = $this->a->customer_id;

    expect(countIn('customer', $a, 'SELECT count(*) AS n FROM account'))->toBe(2)
        ->and(countIn('customer', $a, 'SELECT count(*) AS n FROM ledger_transaction'))->toBe(1)
        ->and(countIn('customer', $a, 'SELECT count(*) AS n FROM ledger_posting'))->toBe(1)
        ->and(countIn('customer', $a, "SELECT count(*) AS n FROM account WHERE kind = 'bank'"))->toBe(0)
        ->and(countIn('customer', $this->b->customer_id, 'SELECT count(*) AS n FROM ledger_posting'))->toBe(3);
});

it('shows nothing with no actor bound', function () {
    foreach (['account', 'ledger_transaction', 'ledger_posting'] as $table) {
        expect(countIn('', null, "SELECT count(*) AS n FROM {$table}"))->toBe(0);
    }
});

it('refuses direct writes from a customer scope', function () {
    $available = Ledger::available($this->a);
    $writes = [
        fn () => DB::insert("INSERT INTO account (kind, customer_id) VALUES ('cust_available', ?)", [$this->a->customer_id]),
        fn () => DB::insert("INSERT INTO ledger_transaction (event_kind, customer_id) VALUES ('topup', ?)", [$this->a->customer_id]),
        fn () => DB::insert('INSERT INTO ledger_posting (ledger_txn_id, account_id, amount) SELECT ledger_txn_id, ?, 1 FROM ledger_transaction LIMIT 1', [$available]),
    ];

    DatabaseActor::push('customer', $this->a->customer_id);

    try {
        foreach ($writes as $write) {
            expect(fn () => DB::transaction($write))->toThrow(QueryException::class, 'row-level security');
        }
    } finally {
        DatabaseActor::pop();
    }
});

it('gives the ledger scope the ledger and nothing else', function () {
    IdentityDocument::factory()->create(['customer_id' => $this->a->customer_id]);

    expect(countIn('ledger', null, 'SELECT count(*) AS n FROM ledger_posting'))->toBe(6)
        ->and(countIn('ledger', null, 'SELECT count(*) AS n FROM account'))->toBe(10)
        ->and(countIn('ledger', null, 'SELECT count(*) AS n FROM customer'))->toBe(0)
        ->and(countIn('ledger', null, 'SELECT count(*) AS n FROM identity_document'))->toBe(0)
        ->and(countIn('ledger', null, 'SELECT count(*) AS n FROM audit_log'))->toBe(0);
});

it('keeps the actor ids when entering the ledger scope', function () {
    DatabaseActor::push('customer', $this->a->customer_id);

    try {
        $seen = DatabaseActor::ledger(fn () => [DatabaseActor::scope(), DB::selectOne('SELECT dahab_current_customer_id()::text AS id')->id]);
    } finally {
        DatabaseActor::pop();
    }

    expect($seen)->toBe(['ledger', $this->a->customer_id])->and(DatabaseActor::scope())->toBe('maintenance');
});

it('protects every ledger table with forced row-level security', function () {
    $rows = collect(DB::select("
        SELECT relname, relrowsecurity AND relforcerowsecurity AS forced,
               (SELECT count(*) FROM pg_policies p WHERE p.tablename = relname) AS policies
        FROM pg_class WHERE relname IN ('account','ledger_transaction','ledger_posting') AND relkind = 'r'
        ORDER BY relname
    "));

    expect($rows->pluck('relname')->all())->toBe(['account', 'ledger_posting', 'ledger_transaction'])
        ->and($rows->every(fn ($r) => $r->forced))->toBeTrue()
        // read + insert, plus a lock-only UPDATE policy where the service locks rows.
        ->and($rows->pluck('policies', 'relname')->map(fn ($n) => (int) $n)->all())->toBe(['account' => 3, 'ledger_posting' => 2, 'ledger_transaction' => 3]);
});

it('lets the ledger scope lock rows but never update them', function () {
    $account = Ledger::available($this->a);

    $locked = DatabaseActor::ledger(fn () => DB::table('account')->where('account_id', $account)->lockForUpdate()->pluck('account_id')->count());

    expect($locked)->toBe(1)
        ->and(fn () => DatabaseActor::ledger(fn () => (DB::update("UPDATE account SET currency = 'USD' WHERE account_id = ?", [$account]))))
        ->toThrow(QueryException::class, 'row-level security');
});
