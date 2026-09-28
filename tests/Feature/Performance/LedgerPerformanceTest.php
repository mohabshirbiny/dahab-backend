<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('perf');

// Spec 008 SC-004, opt-in: `php vendor/bin/pest --group=perf` (excluded from the
// default run in phpunit.xml). 1,000,000 ledger lines: one customer with
// 10,000 movements, and 490,000 two-line top-ups across 2,000 other customers
// over the last year. The seed is bulk SQL with the per-row ledger triggers
// switched off for the insert only (inside the test's transaction, which is
// rolled back); every seeded entry is balanced by construction, and the test
// checks the whole-system total is still zero.

function seedMillionLines(Customer $mona): void
{
    DB::statement('ALTER TABLE ledger_posting DISABLE TRIGGER trg_txn_balanced');
    DB::statement('ALTER TABLE ledger_posting DISABLE TRIGGER trg_customer_nonneg');

    DB::statement("
        INSERT INTO customer (display_ref, phone, full_name, preferred_lang, status, is_verified, is_suspended)
        SELECT 'p'||g, '+2019'||lpad(g::text, 8, '0'), 'Perf '||g, 'ar', 'active', true, false
        FROM generate_series(1, 2000) g
    ");

    $bank = DB::table('account')->where('kind', 'bank')->whereNull('customer_id')->value('account_id');
    $monaAvailable = DB::table('account')->where('customer_id', $mona->customer_id)->where('kind', 'cust_available')->value('account_id');

    // Mona: 10,000 top-ups of 10 EGP over the last year.
    DB::statement("
        WITH t AS (
            INSERT INTO ledger_transaction (event_kind, customer_id, created_at)
            SELECT 'topup', ?, now() - (g * interval '50 minutes')
            FROM generate_series(1, 10000) g
            RETURNING ledger_txn_id
        )
        INSERT INTO ledger_posting (ledger_txn_id, account_id, amount)
        SELECT ledger_txn_id, ?::uuid, -10 FROM t
        UNION ALL
        SELECT ledger_txn_id, ?::uuid, 10 FROM t
    ", [$mona->customer_id, $bank, $monaAvailable]);

    // Everyone else: 490,000 top-ups of 1..100 EGP.
    DB::statement("
        WITH c AS (
            SELECT row_number() OVER () AS n, a.account_id, a.customer_id
            FROM account a JOIN customer cu ON cu.customer_id = a.customer_id
            WHERE a.kind = 'cust_available' AND cu.display_ref LIKE 'p%'
        ), t AS (
            INSERT INTO ledger_transaction (event_kind, customer_id, created_at, memo)
            SELECT 'topup', c.customer_id, now() - (g * interval '64 seconds'), c.account_id::text
            FROM generate_series(1, 490000) g
            JOIN c ON c.n = (g % 2000) + 1
            RETURNING ledger_txn_id, memo
        )
        INSERT INTO ledger_posting (ledger_txn_id, account_id, amount)
        SELECT ledger_txn_id, ?::uuid, -((abs(hashtext(ledger_txn_id::text)) % 100) + 1) FROM t
        UNION ALL
        SELECT ledger_txn_id, memo::uuid, (abs(hashtext(ledger_txn_id::text)) % 100) + 1 FROM t
    ", [$bank]);

    DB::statement('ALTER TABLE ledger_posting ENABLE TRIGGER trg_txn_balanced');
    DB::statement('ALTER TABLE ledger_posting ENABLE TRIGGER trg_customer_nonneg');
    DB::statement('ANALYZE ledger_posting');
    // Freshly bulk-inserted rows pay a one-time visibility check (hint bits) on
    // their first read; ledger rows in production are old. Read them once.
    DB::select('SELECT count(*) FROM ledger_posting');
    DB::select('SELECT count(*) FROM ledger_transaction');
    DB::statement('ANALYZE ledger_transaction');
    DB::statement('ANALYZE account');
}

function timed(Closure $work): float
{
    $start = hrtime(true);
    $work();

    return (hrtime(true) - $start) / 1e9;
}

it('meets SC-004 with 1,000,000 ledger lines', function () {
    $mona = Customer::factory()->verified()->create();
    seedMillionLines($mona);

    expect((int) DB::selectOne('SELECT count(*) AS n FROM ledger_posting')->n)->toBe(1000000)
        ->and((string) DB::selectOne('SELECT must_be_zero::numeric(18,4)::text AS z FROM ledger_global_zero')->z)->toBe('0.0000');

    $token = app(IssueTokenFamilyAction::class)->forCustomer($mona)->accessToken;

    // A customer's wallet and first page of history, 10,000 movements: < 1 s.
    $customer = timed(function () use ($token) {
        $this->bearer($token)->getJson('/api/v1/customer/me/wallet')->assertOk()->assertJsonPath('data.available', '100000.0000');
        $this->bearer($token)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->assertJsonCount(25, 'data');
    });

    // A one-month statement of all customer wallets, first page: < 2 s.
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $month = ['view' => 'customers', 'from' => now('Africa/Cairo')->subDays(30)->format('Y-m-d'), 'to' => now('Africa/Cairo')->format('Y-m-d')];
    $staff = timed(function () use ($finance, $month) {
        $this->bearer(staffAccessToken($finance))->getJson('/api/v1/dashboard/wallet-statement?'.http_build_query($month))->assertOk();
    });

    fwrite(STDERR, sprintf("\n  SC-004: customer wallet + history %.3fs, one-month statement %.3fs\n", $customer, $staff));

    expect($customer)->toBeLessThan(1.0)->and($staff)->toBeLessThan(2.0);
});
