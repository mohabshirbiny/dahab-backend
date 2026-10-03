<?php

use App\Models\PayoutAccount;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 T008: the database backstops — forced RLS on the five tables, the
// guards (DH007 / DH008), the deferred money check, the CHECKs, append-only
// history, one account in use and one open confirmation per customer.

beforeEach(function () {
    Notification::fake();
});

function withdrawalSqlState(Closure $work): ?string
{
    try {
        DB::transaction(function () use ($work) {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $work();
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? 'error';
    }

    return null;
}

it('forces row-level security with a policy on every new table', function () {
    foreach (['payout_account', 'payout_account_change', 'withdrawal_pause', 'withdrawal', 'withdrawal_confirmation'] as $table) {
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        $policies = DB::selectOne('SELECT count(*) AS n FROM pg_policies WHERE tablename = ?', [$table])->n;
        expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue()->and($policies)->toBeGreaterThan(0);
    }
});

it('guards the account machine and freezes its details (DH008)', function () {
    $account = PayoutAccount::factory()->create();

    expect(withdrawalSqlState(fn () => DB::table('payout_account')->where('payout_account_id', $account->payout_account_id)->update(['state' => 'removing'])))->toBe('DH008')
        ->and(withdrawalSqlState(fn () => DB::table('payout_account')->where('payout_account_id', $account->payout_account_id)->update(['account_name' => 'Somebody Else'])))->toBe('DH008')
        ->and(withdrawalSqlState(fn () => DB::table('payout_account')->where('payout_account_id', $account->payout_account_id)->delete()))->toBe('DH008')
        ->and(withdrawalSqlState(fn () => DB::table('payout_account')->where('payout_account_id', $account->payout_account_id)->update(['state' => 'refused'])))->toBe('23514')
        ->and(withdrawalSqlState(fn () => DB::table('payout_account')->where('payout_account_id', $account->payout_account_id)->update(['is_in_use' => true])))->toBe('23514');
});

it('refuses a malformed number and a second account in use', function () {
    $account = PayoutAccount::factory()->verified()->create();

    expect(withdrawalSqlState(fn () => PayoutAccount::factory()->create(['account_number_or_iban' => 'EG12'])))->toBe('23514')
        ->and(withdrawalSqlState(fn () => PayoutAccount::factory()->verified()->create(['customer_id' => $account->customer_id])))->toBe('23505');
});

it('guards the withdrawal machine, its identity and its money links (DH007)', function () {
    $w = Withdrawals::requested($this, '1000', '5000');

    expect(withdrawalSqlState(fn () => DB::table('withdrawal')->where('withdrawal_id', $w->withdrawal_id)->update(['state' => 'released'])))->toBe('DH007')
        ->and(withdrawalSqlState(fn () => DB::table('withdrawal')->where('withdrawal_id', $w->withdrawal_id)->update(['amount' => 2000])))->toBe('DH007')
        ->and(withdrawalSqlState(fn () => DB::table('withdrawal')->where('withdrawal_id', $w->withdrawal_id)->delete()))->toBe('DH007')
        // cancelled without its return entry: the deferred money check refuses the commit.
        ->and(withdrawalSqlState(fn () => DB::table('withdrawal')->where('withdrawal_id', $w->withdrawal_id)->update(['state' => 'cancelled', 'ended_at' => now()])))->toBe('DH007')
        // a held withdrawal is never released (CHECK) — and never released held via the shape check either.
        ->and(withdrawalSqlState(fn () => DB::table('withdrawal')->where('withdrawal_id', $w->withdrawal_id)->update(['held_at' => now(), 'held_by' => SystemActor::id(), 'hold_reason' => 'other', 'hold_message' => 'xyz', 'hold_note' => 'xyz'])))->toBe('23514');
});

it('keeps the account history append-only and allows one open confirmation per customer', function () {
    $w = Withdrawals::requested($this, '1000', '5000');
    $change = DB::table('payout_account_change')->where('customer_id', $w->customer_id)->first();

    expect(withdrawalSqlState(fn () => DB::table('payout_account_change')->where('change_id', $change->change_id)->update(['kind' => 'removed'])))->not->toBeNull()
        ->and(withdrawalSqlState(fn () => DB::table('withdrawal_confirmation')->insert([
            ['customer_id' => $w->customer_id, 'payout_account_id' => $w->payout_account_id, 'amount' => 1, 'token_hash' => 'a', 'expires_at' => now()->addHour()],
            ['customer_id' => $w->customer_id, 'payout_account_id' => $w->payout_account_id, 'amount' => 1, 'token_hash' => 'b', 'expires_at' => now()->addHour()],
        ])))->toBe('23505');
});

it('links the ledger to its withdrawal', function () {
    $fk = DB::selectOne("SELECT condeferrable FROM pg_constraint WHERE conname = 'lt_withdrawal_fk'");
    expect($fk->condeferrable)->toBeTrue();
});

it('shows a customer only their own rows of every new table', function () {
    $mine = Withdrawals::requested($this, '1000', '5000');
    $other = Withdrawals::requested($this, '1000', '5000');

    DatabaseActor::push('customer', $mine->customer_id);
    try {
        foreach (['payout_account', 'payout_account_change', 'withdrawal', 'withdrawal_confirmation'] as $table) {
            expect(DB::table($table)->distinct()->pluck('customer_id')->all())->toBe([$mine->customer_id]);
        }
        expect(DB::table('withdrawal')->where('withdrawal_id', $other->withdrawal_id)->update(['ended_at' => null]))->toBe(0);
    } finally {
        DatabaseActor::pop();
    }

    DatabaseActor::push('');
    try {
        expect(DB::table('withdrawal')->count())->toBe(0)->and(DB::table('payout_account')->count())->toBe(0);
    } finally {
        DatabaseActor::pop();
    }
});
