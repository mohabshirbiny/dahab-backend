<?php

use App\Actions\TopUp\CancelTopUpNoticeAction;
use App\Actions\TopUp\MatchTopUpAction;
use App\Enums\TopUpStatus;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 009 FR-017, FR-025, SC-004: two staff matching the same notice, or a
// staff match racing the customer's cancel — exactly one wins, and a notice
// is never credited twice or credited after being cancelled. Same technique
// as spec 008's LedgerConcurrencyTest: connection A holds its transaction
// open, connection B must wait for A's row lock; after A commits, B is
// refused. Fixtures are committed and removed at the end.

function topUpOnConnection(string $name, Closure $work): mixed
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
function blockedWhileAHoldsTheRow(Closure $attempt): bool
{
    return topUpOnConnection('pgsql_b', function () use ($attempt) {
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

/** @return 'refused'|'ran' — B after A committed. */
function afterACommitted(Closure $attempt): string
{
    return topUpOnConnection('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return $e->errorCode === 'illegal_topup_transition' ? 'refused' : 'other:'.$e->errorCode;
        }
    });
}

function withCommittedFixtures(Closure $test): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack(); // leave RefreshDatabase's transaction: B must see committed rows
    DatabaseActor::reapply();

    $customer = Customer::factory()->verified()->create();
    // No roles: a role row created here would be committed and outlive the test.
    $staff = Staff::factory()->withRole()->create();
    $other = Staff::factory()->withRole()->create();
    $account = ReceivingAccount::factory()->instapay()->create();
    $topUp = TopUp::factory()->create(['customer_id' => $customer->customer_id, 'notice_account_id' => $account->receiving_account_id]);

    try {
        $test($customer, $staff, $other, $account, $topUp);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
        // TRUNCATE skips the row-level no-delete triggers; the ledger singletons are re-provisioned.
        DB::statement('TRUNCATE customer_notification, topup, receiving_account, ledger_posting, ledger_transaction, account, audit_log CASCADE');
        DB::table('customer')->where('customer_id', $customer->customer_id)->delete();
        DB::table('staff')->whereIn('staff_id', [$staff->staff_id, $other->staff_id])->delete();
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        DB::purge('pgsql_b');
        DB::beginTransaction(); // hand RefreshDatabase a transaction to roll back
    }
}

it('lets exactly one of two matches credit the notice', function () {
    withCommittedFixtures(function (Customer $customer, Staff $staff, Staff $other, ReceivingAccount $account, TopUp $topUp) {
        $match = fn (Staff $by) => app(MatchTopUpAction::class)->handle($by, $topUp->topup_id, '20000', $account->receiving_account_id, null, null);

        DB::beginTransaction(); // A matches and keeps its lock
        $match($staff);

        $waited = blockedWhileAHoldsTheRow(fn () => DB::transaction(fn () => $match($other)));

        DB::commit();
        DatabaseActor::reapply();

        $second = afterACommitted(fn () => $match($other));
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and($second)->toBe('refused')
            ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(1)
            ->and(TopUp::query()->find($topUp->topup_id)->credited_by)->toBe($staff->staff_id)
            ->and(bccomp((string) DB::table('ledger_posting')->sum('amount'), '0', 4))->toBe(0);
    });
});

it('never credits a notice the customer cancelled, and never cancels a credited one', function () {
    withCommittedFixtures(function (Customer $customer, Staff $staff, Staff $other, ReceivingAccount $account, TopUp $topUp) {
        $second = TopUp::factory()->create(['customer_id' => $customer->customer_id, 'notice_account_id' => $account->receiving_account_id]);
        $cancel = fn (TopUp $t) => app(CancelTopUpNoticeAction::class)->handle($customer, $t->topup_id);
        $match = fn (TopUp $t) => app(MatchTopUpAction::class)->handle($staff, $t->topup_id, '20000', $account->receiving_account_id, null, null);

        // Cancel first, match loses.
        DB::beginTransaction();
        $cancel($topUp);
        $waited = blockedWhileAHoldsTheRow(fn () => DB::transaction(fn () => $match($topUp)));
        DB::commit();
        DatabaseActor::reapply();
        $matchAfterCancel = afterACommitted(fn () => $match($topUp));
        DatabaseActor::reapply();

        // Match first, cancel loses.
        DB::beginTransaction();
        $match($second);
        $waited2 = blockedWhileAHoldsTheRow(fn () => DB::transaction(fn () => $cancel($second)));
        DB::commit();
        DatabaseActor::reapply();
        $cancelAfterMatch = afterACommitted(fn () => $cancel($second));
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()->and($waited2)->toBeTrue()
            ->and($matchAfterCancel)->toBe('refused')
            ->and($cancelAfterMatch)->toBe('refused')
            ->and(TopUp::query()->find($topUp->topup_id)->status)->toBe(TopUpStatus::CANCELLED)
            ->and(TopUp::query()->find($topUp->topup_id)->ledger_txn_id)->toBeNull()
            ->and(TopUp::query()->find($second->topup_id)->status)->toBe(TopUpStatus::CREDITED)
            ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(1)
            ->and(bccomp((string) DB::table('ledger_posting')->sum('amount'), '0', 4))->toBe(0);
    });
});
