<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Finance;

uses(RefreshDatabase::class);

// Spec 015 SC-006 / research R10: the Overview, the bank book and the
// compensation list stay fast with 10,000 bank postings; the bank book
// exports them. Rows are written in bulk (maintenance scope) in one balanced
// pair per entry, the deferred checks still satisfied.

/** p95 of `$runs` timings of `$call`, in ms. */
function financeP95(int $runs, Closure $call): float
{
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $call();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    return $times[(int) ceil(0.95 * $runs) - 1];
}

it('answers within 300 ms at p95 with 10,000 bank postings, and exports them', function () {
    $customer = Finance::customer('0');
    DatabaseActor::elevate('maintenance', function () use ($customer) {
        $bank = Account::internal(AccountKind::BANK);
        $available = Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE);
        DB::statement("
            WITH t AS (
              INSERT INTO ledger_transaction (ledger_txn_id, event_kind, customer_id, created_at)
              SELECT gen_random_uuid(), 'topup', ?::uuid, now() - (g || ' minutes')::interval FROM generate_series(1, 10000) g
              RETURNING ledger_txn_id)
            INSERT INTO ledger_posting (ledger_txn_id, account_id, amount)
            SELECT ledger_txn_id, ?::uuid, -100 FROM t UNION ALL SELECT ledger_txn_id, ?::uuid, 100 FROM t
        ", [$customer->customer_id, $bank, $available]);
        // Fresh planner statistics for every table, as autovacuum keeps them in production.
        DB::statement('ANALYZE');
    });

    Finance::staff($this, SeedRole::CEO);
    $this->getJson('/api/v1/dashboard/overview')->assertOk();

    expect(financeP95(10, fn () => $this->getJson('/api/v1/dashboard/overview')->assertOk()))->toBeLessThan(300.0)
        ->and(financeP95(10, fn () => $this->getJson('/api/v1/dashboard/bank-book')->assertOk()))->toBeLessThan(300.0)
        ->and(financeP95(10, fn () => $this->getJson('/api/v1/dashboard/compensation')->assertOk()))->toBeLessThan(300.0);

    $t = hrtime(true);
    $csv = (string) $this->get('/api/v1/dashboard/bank-book/export?from='.now('Africa/Cairo')->subDays(10)->toDateString())->assertOk()->getContent();
    expect((hrtime(true) - $t) / 1e9)->toBeLessThan(10.0)
        ->and(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(10000);
});
