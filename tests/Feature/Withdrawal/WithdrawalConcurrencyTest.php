<?php

use App\Actions\Payouts\Customer\UsePayoutAccountAction;
use App\Actions\Payouts\Staff\RefusePayoutAccountAction;
use App\Actions\Payouts\Staff\VerifyPayoutAccountAction;
use App\Actions\Withdrawals\Customer\CancelWithdrawalAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Actions\Withdrawals\Staff\RejectWithdrawalAction;
use App\Actions\Withdrawals\Staff\ReleaseWithdrawalAction;
use App\Actions\Withdrawals\Staff\TakeForReviewAction;
use App\Enums\PayoutRefusalReason;
use App\Enums\SeedRole;
use App\Enums\WithdrawalRejectReason;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Ledger;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 T059, research R6/R7: two writers on the same customer's money.
// Connection A holds its transaction open; B must wait for A's row lock;
// after A commits, B changes nothing it should not. Exactly one wins and the
// money moves once. The spec 012 technique: committed fixtures, two
// connections, then every committed row is removed.

function wdOnConnection(string $name, Closure $work): mixed
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

/** B's attempt must first block on A's lock (55P03 with a short lock_timeout). */
function wdBlockedWhileAHolds(Closure $attempt): bool
{
    return wdOnConnection('pgsql_b', function () use ($attempt) {
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

/** @return string 'ran' | 'refused:<code>' — B after A committed. */
function wdAfterACommitted(Closure $attempt): string
{
    return wdOnConnection('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

function wdAs(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
{
    DatabaseActor::push($scope, customerId: $customerId, staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

/** How many ledger entries the withdrawal has. */
function wdEntries(Withdrawal $w): int
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('withdrawal_id', $w->withdrawal_id)->count());
}

function wdFresh(Withdrawal $w): Withdrawal
{
    return DatabaseActor::elevate('maintenance', fn () => Withdrawal::query()->findOrFail($w->withdrawal_id));
}

function withCommittedWithdrawal(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();
    Notification::fake();

    try {
        $fixtures = DB::transaction(fn () => $build());
        Auth::forgetGuards();
        app()->forgetInstance(RequestContext::class);
        $race(...$fixtures);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
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
        DB::purge('pgsql_b');
        DB::beginTransaction();
    }
}

/** A withdrawal under review, committed: [withdrawal, customer, finance]. */
function committedUnderReview($test): array
{
    $w = Withdrawals::underReview($test);

    return [$w, Withdrawals::customer($w), Staff::query()->findOrFail($w->reviewed_by)];
}

it('lets a release or the customer\'s cancel win, never both', function () {
    withCommittedWithdrawal(fn () => committedUnderReview($this), function (Withdrawal $w, Customer $customer, Staff $finance) {
        $release = fn () => wdAs('staff', null, $finance->staff_id, fn () => app(ReleaseWithdrawalAction::class)->handle($finance, $w->withdrawal_id, 'FT1', null, null));
        $cancel = fn () => wdAs('customer', $customer->customer_id, null, fn () => app(CancelWithdrawalAction::class)->handle($customer, $w->withdrawal_id));

        DB::beginTransaction();
        $release();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($cancel));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($cancel)))->toBe('refused:illegal_withdrawal_transition')
            ->and(wdFresh($w)->state->value)->toBe('released')
            ->and(wdEntries($w))->toBe(2);
    });
});

it('lets a change of the account in use or a release win, never both', function () {
    withCommittedWithdrawal(function () {
        [$w, $customer, $finance] = committedUnderReview($this);
        $b = Withdrawals::verifiedAccount($this, $customer, ['account_number_or_iban' => '1234567890']);

        return [$w, $customer, $finance, $b];
    }, function (Withdrawal $w, Customer $customer, Staff $finance, PayoutAccount $b) {
        $use = fn () => wdAs('customer', $customer->customer_id, null, fn () => app(UsePayoutAccountAction::class)->handle($customer, $b->payout_account_id));
        $release = fn () => wdAs('staff', null, $finance->staff_id, fn () => app(ReleaseWithdrawalAction::class)->handle($finance, $w->withdrawal_id, 'FT1', null, null));

        DB::beginTransaction();
        $use();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($release));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($release)))->toBe('refused:illegal_withdrawal_transition')
            ->and(wdFresh($w)->state->value)->toBe('cancelled')
            ->and(wdFresh($w)->release_txn_id)->toBeNull()
            ->and(wdEntries($w))->toBe(2);
    });
});

it('never lets two holds take more than is available', function () {
    withCommittedWithdrawal(function () {
        $customer = Withdrawals::funded('5000');
        $account = Withdrawals::verifiedAccount($this, $customer);
        $confirmation = Withdrawals::confirmedFor($this, $customer, '4000', $account->payout_account_id);

        return [$customer, $account, $confirmation];
    }, function (Customer $customer, PayoutAccount $account, string $confirmation) {
        $submit = fn () => wdAs('customer', $customer->customer_id, null, fn () => app(SubmitWithdrawalAction::class)->handle($customer, $confirmation, '4000', $account->payout_account_id));
        // Another spend of the same wallet (a deposit hold) at the same moment.
        $hold = fn () => DatabaseActor::elevate('maintenance', fn () => Ledger::hold($customer, '4000'));

        DB::beginTransaction();
        $submit();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($hold));
        DB::commit();
        DatabaseActor::reapply();

        $available = DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting')->where('account_id', Ledger::available($customer))->sum('amount'));

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($hold)))->toBe('refused:insufficient_funds')
            ->and(bccomp($available, '1000', 4))->toBe(0);
    });
});

it('lets a verification or a refusal win, never both', function () {
    withCommittedWithdrawal(function () {
        $customer = Withdrawals::funded('0');
        Withdrawals::add($this, $customer)->assertCreated();
        $account = PayoutAccount::query()->where('customer_id', $customer->customer_id)->sole();
        $verifier = Staff::factory()->role(SeedRole::VERIFICATION)->create();
        $finance = Staff::factory()->role(SeedRole::FINANCE)->create();

        return [$account, $verifier, $finance];
    }, function (PayoutAccount $account, Staff $verifier, Staff $finance) {
        $verify = fn () => wdAs('staff', null, $verifier->staff_id, fn () => app(VerifyPayoutAccountAction::class)->handle($verifier, $account->payout_account_id));
        $refuse = fn () => wdAs('staff', null, $finance->staff_id, fn () => app(RefusePayoutAccountAction::class)->handle($finance, $account->payout_account_id, PayoutRefusalReason::OTHER, 'race'));

        DB::beginTransaction();
        $verify();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($refuse));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($refuse)))->toBe('refused:illegal_payout_account_transition')
            ->and(DatabaseActor::elevate('maintenance', fn () => PayoutAccount::query()->find($account->payout_account_id)->state->value))->toBe('active');
    });
});

it('lets a rejection or the customer\'s cancel win, never both', function () {
    withCommittedWithdrawal(fn () => committedUnderReview($this), function (Withdrawal $w, Customer $customer, Staff $finance) {
        $reject = fn () => wdAs('staff', null, $finance->staff_id, fn () => app(RejectWithdrawalAction::class)->handle($finance, $w->withdrawal_id, WithdrawalRejectReason::OTHER, 'race'));
        $cancel = fn () => wdAs('customer', $customer->customer_id, null, fn () => app(CancelWithdrawalAction::class)->handle($customer, $w->withdrawal_id));

        DB::beginTransaction();
        $reject();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($cancel));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($cancel)))->toBe('refused:illegal_withdrawal_transition')
            ->and(wdFresh($w)->state->value)->toBe('rejected')
            ->and(wdEntries($w))->toBe(2);
    });
});

it('lets two staff take the same withdrawal for review once', function () {
    withCommittedWithdrawal(function () {
        $w = Withdrawals::requested($this, '1000', '5000');

        return [$w, Staff::factory()->role(SeedRole::FINANCE)->create(), Staff::factory()->role(SeedRole::FINANCE)->create()];
    }, function (Withdrawal $w, Staff $a, Staff $b) {
        $take = fn (Staff $s) => fn () => wdAs('staff', null, $s->staff_id, fn () => app(TakeForReviewAction::class)->handle($s, $w->withdrawal_id));

        DB::beginTransaction();
        $take($a)();
        $waited = wdBlockedWhileAHolds(fn () => DB::transaction($take($b)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(wdAfterACommitted(fn () => DB::transaction($take($b))))->toBe('refused:illegal_withdrawal_transition')
            ->and(wdFresh($w)->reviewed_by)->toBe($a->staff_id);
    });
});
