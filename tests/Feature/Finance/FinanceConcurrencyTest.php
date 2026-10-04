<?php

use App\Actions\Disputes\Staff\ResolveDisputeAction;
use App\Actions\Finance\AdjustWalletAction;
use App\Actions\Finance\CloseDayAction;
use App\Actions\Finance\PayDirectCompensationAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Enums\AccountKind;
use App\Enums\AdjustmentDirection;
use App\Enums\CompensationReason;
use App\Enums\DisputeOutcome;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use App\Support\SystemActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Ledger;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 015 SC-001, research R15 (the spec 012–014 technique): connection A
// holds its transaction; B must wait for A's lock; after A commits, B either
// changes nothing or is refused. Money moves once, never below zero, never
// over a cap; a closed day's snapshot is consistent.

function fcOn(string $name, Closure $work): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);
    DatabaseActor::reapply();

    try {
        return $work();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function fcBlocked(Closure $attempt): bool
{
    return fcOn('pgsql_b', function () use ($attempt) {
        DB::statement("SET lock_timeout = '300ms'");
        try {
            $attempt();

            return false;
        } catch (QueryException $e) {
            return ($e->errorInfo[0] ?? null) === '55P03';
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET lock_timeout = 0');
        }
    });
}

/** @return 'ran'|'refused:<code>' */
function fcAfter(Closure $attempt): string
{
    return fcOn('pgsql_b', function () use ($attempt) {
        try {
            DB::transaction($attempt);

            return 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

function fcAs(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
{
    DatabaseActor::push($scope, customerId: $customerId, staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

function fcAvailable(Customer $customer): string
{
    return DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting')
        ->where('account_id', Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE))
        ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS s')->value('s'));
}

function fcCommitted(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();
    Storage::fake('identity_private');
    Notification::fake();

    try {
        Orders::workedPrices();
        $fixtures = DB::transaction(fn () => $build());
        Auth::forgetGuards();
        app()->forgetInstance(RequestContext::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $race(...$fixtures);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        CarbonImmutable::setTestNow();
        DatabaseActor::reapply();
        DatabaseActor::elevate('maintenance', function () {
            $keep = ['migrations', 'karat', 'karat_price_adjustment', 'legal_document', 'listing_transition', 'buy_request_transition',
                'order_transition', 'withdrawal_transition', 'payout_account_transition', 'dispute_transition', 'extension_request_transition',
                'piece_type', 'setting', 'staff', 'branch'];
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename')
                ->reject(fn ($t) => in_array($t, $keep, true))->map(fn ($t) => '"'.$t.'"')->implode(', ');
            DB::statement("TRUNCATE {$tables} CASCADE");
            DB::table('staff')->where('staff_id', '!=', SystemActor::id())->delete();
            DB::table('branch')->delete();
            // workedPrices() changed the kept 21K adjustments: put the seeded ±15 back.
            DB::table('karat_price_adjustment')->where('karat_code', 21)->where('side', 'buy')->update(['kind' => 'fixed', 'value' => '-15']);
            DB::table('karat_price_adjustment')->where('karat_code', 21)->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '15']);
        });
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::purge('pgsql_b');
        DB::beginTransaction();
    }
}

function fcDebit(Staff $ceo, Customer $customer, string $amount): Closure
{
    return fn () => fcAs('staff', null, $ceo->staff_id, fn () => app(AdjustWalletAction::class)
        ->handle($ceo, $customer->customer_id, AdjustmentDirection::DEBIT, $amount, 'Correcting a duplicated top-up.'));
}

it('lets one of two debits at once go through, never below zero', function () {
    fcCommitted(fn () => [Finance::customer('1000'), Staff::factory()->founder()->create()], function (Customer $customer, Staff $ceo) {
        DB::beginTransaction();
        fcDebit($ceo, $customer, '700')();

        $waited = fcBlocked(fn () => DB::transaction(fcDebit($ceo, $customer, '700')));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(fcAfter(fcDebit($ceo, $customer, '700')))->toBe('refused:insufficient_funds')
            ->and(fcAvailable($customer))->toBe('300.0000')
            ->and(Finance::globalSum())->toBe('0.0000');
    });
});

it('lets a debit and a withdrawal race for the same money: one wins, never below zero', function () {
    fcCommitted(function () {
        $customer = Withdrawals::funded('1000');
        $account = Withdrawals::verifiedAccount($this, $customer);
        $confirmation = Withdrawals::confirmedFor($this, $customer, '700', $account->payout_account_id);

        return [$customer, $account, $confirmation, Staff::factory()->founder()->create()];
    }, function (Customer $customer, PayoutAccount $account, string $confirmation, Staff $ceo) {
        DB::beginTransaction();
        fcDebit($ceo, $customer, '700')();

        $withdraw = fn () => fcAs('customer', $customer->customer_id, null, fn () => app(SubmitWithdrawalAction::class)
            ->handle($customer, $confirmation, '700', $account->payout_account_id));
        $waited = fcBlocked(fn () => DB::transaction($withdraw));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(fcAfter($withdraw))->toBe('refused:insufficient_funds')
            ->and(fcAvailable($customer))->toBe('300.0000')
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('withdrawal')->count()))->toBe(0);
    });
});

it('holds one payer\'s day cap across a direct payment and a dispute payment at once', function () {
    fcCommitted(function () {
        $order = Disputes::awaitingBalance($this);
        Disputes::opened($this, $order);

        return [Disputes::of($order), Finance::customer('0')];
    }, function (Dispute $dispute, Customer $customer) {
        $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
        // 3,000 of the 5,000 day already paid, committed.
        foreach (['2000', '1000'] as $amount) {
            fcAs('staff', null, $finance->staff_id, fn () => app(PayDirectCompensationAction::class)
                ->handle($finance, $customer->customer_id, $amount, CompensationReason::GOODWILL, 'Earlier goodwill payment.'));
        }

        DB::beginTransaction();
        fcAs('staff', null, $finance->staff_id, fn () => app(PayDirectCompensationAction::class)
            ->handle($finance, $customer->customer_id, '2000', CompensationReason::WASTED_TRIP, 'A wasted trip to the branch.'));

        $resolve = fn () => fcAs('staff', null, $finance->staff_id, fn () => app(ResolveDisputeAction::class)
            ->handle($finance, $dispute->dispute_id, DisputeOutcome::RESUME, 'Resolved with compensation for the delay.',
                ['party' => 'buyer', 'amount' => '2000', 'reason' => CompensationReason::IGI_DELAY, 'note' => 'IGI kept the piece too long.']));
        $waited = fcBlocked(fn () => DB::transaction($resolve));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(fcAfter($resolve))->toBe('refused:compensation_cap_exceeded')
            ->and(DatabaseActor::elevate('maintenance', fn () => (string) DB::table('compensation')->sum('amount')))->toBe('5000.0000');
    });
});

it('closes a day once when two people close it together', function () {
    $yesterday = now('Africa/Cairo')->subDay()->toDateString();
    fcCommitted(fn () => [Staff::factory()->role(SeedRole::FINANCE)->create(), Staff::factory()->role(SeedRole::FINANCE)->create()],
        function (Staff $a, Staff $b) use ($yesterday) {
            $close = fn (Staff $by) => fn () => fcAs('staff', null, $by->staff_id, fn () => app(CloseDayAction::class)->handle($by, $yesterday, '0', null));

            DB::beginTransaction();
            $close($a)();

            $waited = fcBlocked(fn () => DB::transaction($close($b)));
            DB::commit();
            DatabaseActor::reapply();

            expect($waited)->toBeTrue()
                ->and(fcAfter($close($b)))->toBe('refused:day_already_closed')
                ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('daily_close')->value('closed_by')))->toBe($a->staff_id);
        });
});

it('waits for an entry stamped before midnight that is still committing, and counts it', function () {
    $yesterday = now('Africa/Cairo')->subDay()->toDateString();
    fcCommitted(fn () => [Finance::customer('0'), Staff::factory()->role(SeedRole::FINANCE)->create()],
        function (Customer $customer, Staff $finance) use ($yesterday) {
            // A: a top-up stamped 23:59:59 yesterday, not yet committed.
            DB::beginTransaction();
            DatabaseActor::elevate('maintenance', function () use ($customer, $yesterday) {
                $id = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
                DB::insert("INSERT INTO ledger_transaction (ledger_txn_id, event_kind, customer_id, created_at) VALUES (?, 'topup', ?, (?::timestamp AT TIME ZONE 'Africa/Cairo'))",
                    [$id, $customer->customer_id, $yesterday.' 23:59:59']);
                DB::insert('INSERT INTO ledger_posting (ledger_txn_id, account_id, amount) VALUES (?, ?, -500), (?, ?, 500)',
                    [$id, Account::internal(AccountKind::BANK), $id, Ledger::available($customer)]);
            });

            $close = fn () => fcAs('staff', null, $finance->staff_id, fn () => app(CloseDayAction::class)->handle($finance, $yesterday, '500', null));
            $waited = fcBlocked(fn () => DB::transaction($close));
            DB::commit();
            DatabaseActor::reapply();

            expect($waited)->toBeTrue()
                ->and(fcAfter($close))->toBe('ran')
                ->and(DatabaseActor::elevate('maintenance', fn () => (array) DB::table('daily_close')->first(['books_bank', 'difference', 'is_locked'])))
                ->toEqual(['books_bank' => '500.0000', 'difference' => '0.0000', 'is_locked' => true]);
        });
});
