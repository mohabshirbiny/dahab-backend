<?php

use App\Actions\Withdrawals\Customer\RequestWithdrawalConfirmationAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Models\WithdrawalPause;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class)->group('perf');

// Spec 013 plan "Performance Goals" (task T062), opt-in: `php vendor/bin/pest
// --group=perf` (excluded from the default run in phpunit.xml). The staff list
// answers in < 300 ms p95 over 10,000 withdrawals; one sweep pass tells 1,000
// customers their pause ended in under a minute. Every withdrawal is made by the
// real request-confirmation and submit Actions (the hold posted, the row
// created), committed as in production, and removed at the end. Only the click
// on the email link is stood in for (the confirmation is marked confirmed),
// since the token is never stored. PERF_WITHDRAWALS overrides the 10,000.

/**
 * `$count` requested withdrawals, committed, spread over `$customers` funded
 * customers with a verified account in use.
 */
function perfRequestedWithdrawals(int $count, int $customers = 100): void
{
    $people = array_map(function () {
        $customer = Withdrawals::funded('100000000');
        $account = DB::transaction(fn () => PayoutAccount::factory()->verified()->create(['customer_id' => $customer->customer_id]));

        return [$customer, $account->payout_account_id];
    }, range(1, $customers));
    $request = app(RequestWithdrawalConfirmationAction::class);
    $submit = app(SubmitWithdrawalAction::class);

    for ($i = 0; $i < $count; $i++) {
        [$customer, $accountId] = $people[$i % $customers];
        DatabaseActor::push('customer', customerId: $customer->customer_id);
        try {
            $confirmation = $request->handle($customer, '100', $accountId);
            DatabaseActor::elevate('maintenance', fn () => DB::table('withdrawal_confirmation')
                ->where('confirmation_id', $confirmation->confirmation_id)->update(['confirmed_at' => now()]));
            $submit->handle($customer, $confirmation->confirmation_id, '100', $accountId);
        } finally {
            DatabaseActor::pop();
        }
    }
    DB::statement('ANALYZE withdrawal, withdrawal_confirmation, payout_account, ledger_transaction, ledger_posting, customer');
}

/** Leave RefreshDatabase's transaction, run `$work` committed, then remove every committed row. */
function perfWithdrawalsCommitted(Closure $work): void
{
    Storage::fake('identity_private');
    Notification::fake();
    Queue::fake();
    DB::rollBack();
    DatabaseActor::reapply();

    try {
        $work();
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reset();
        DatabaseActor::elevate('maintenance', function () {
            $keep = ['migrations', 'karat', 'karat_price_adjustment', 'legal_document', 'listing_transition', 'buy_request_transition',
                'order_transition', 'withdrawal_transition', 'payout_account_transition', 'piece_type', 'setting', 'staff', 'branch'];
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename')
                ->reject(fn ($t) => in_array($t, $keep, true))->map(fn ($t) => '"'.$t.'"')->implode(', ');
            DB::statement("TRUNCATE {$tables} CASCADE");
            DB::table('staff')->where('staff_id', '!=', SystemActor::id())->delete();
            DB::table('branch')->delete();
        });
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::beginTransaction(); // hand RefreshDatabase a transaction to roll back
    }
}

it('lists the open withdrawals in under 300 ms over 10,000 withdrawals', function () {
    perfWithdrawalsCommitted(function () {
        $count = (int) (getenv('PERF_WITHDRAWALS') ?: 10000);
        $build = hrtime(true);
        perfRequestedWithdrawals($count);
        fwrite(STDERR, sprintf("\n[perf] built %d withdrawals in %.0f s\n", $count, (hrtime(true) - $build) / 1e9));

        Withdrawals::staff($this, SeedRole::FINANCE);
        $url = Withdrawals::STAFF.'/withdrawals?per_page=25';
        $this->getJson($url)->assertOk(); // warm up
        $times = [];
        foreach (range(1, 20) as $_) {
            $start = hrtime(true);
            $this->getJson($url)->assertOk();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $p95 = $times[18];
        fwrite(STDERR, sprintf("[perf] GET /dashboard/withdrawals over %d: median %.0f ms · p95 %.0f ms\n", $count, $times[10], $p95));

        expect($p95)->toBeLessThan(300);
    });
});

it('tells 1,000 customers their pause ended in one sweep pass in under a minute', function () {
    perfWithdrawalsCommitted(function () {
        $customers = Customer::factory()->verified()->count(1000)->create();
        DatabaseActor::elevate('maintenance', function () use ($customers) {
            foreach ($customers as $customer) {
                WithdrawalPause::query()->create([
                    'customer_id' => $customer->customer_id,
                    'opened_at' => now()->subHours(50),
                    'pause_until' => now()->subHours(2),
                ]);
            }
        });

        $start = hrtime(true);
        Withdrawals::sweep();
        $seconds = (hrtime(true) - $start) / 1e9;
        fwrite(STDERR, sprintf("\n[perf] sweep of 1,000 ended pauses: %.1f s\n", $seconds));

        expect(DatabaseActor::elevate('maintenance', fn () => WithdrawalPause::query()->whereNotNull('ended_notified_at')->count()))->toBe(1000)
            ->and($seconds)->toBeLessThan(60);
    });
});
