<?php

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 edge case "two operations spend the same balance at the same
// moment", research R5. A single PHP process cannot run two blocking
// transactions at once, so connection A holds its entry open and connection
// B proves it has to wait for A's lock; after A commits, B sees A's lines and
// is refused. The fixtures are committed (outside RefreshDatabase's
// transaction) and removed at the end.

function holdEntry(Customer $customer, string $amount): LedgerEntry
{
    return new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [
        new LedgerLine(Ledger::available($customer), '-'.$amount),
        new LedgerLine(Ledger::held($customer), $amount),
    ], actorCustomerId: $customer->customer_id);
}

function onConnection(string $name, Closure $work): mixed
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

it('serialises two holds on one wallet so the balance never goes negative', function () {
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);

    DB::rollBack(); // leave RefreshDatabase's transaction: B must see committed rows
    DatabaseActor::reapply(); // the scope settings were rolled back with it

    $customer = Customer::factory()->create();

    try {
        Ledger::topUp($customer, '1000');

        // A: hold 700 and keep the transaction (and the account lock) open.
        DB::beginTransaction();
        app(PostLedgerEntryAction::class)->handle(holdEntry($customer, '700'));

        // B: the same hold must wait for A's lock.
        $waited = onConnection('pgsql_b', function () use ($customer) {
            DB::statement("SET lock_timeout = '300ms'");
            DB::beginTransaction();

            try {
                app(PostLedgerEntryAction::class)->handle(holdEntry($customer, '700'));

                return false;
            } catch (QueryException $e) {
                return ($e->errorInfo[0] ?? null) === '55P03'; // lock_not_available
            } finally {
                DB::rollBack();
            }
        });

        DB::commit(); // A commits: 300 available, 700 held.
        DatabaseActor::reapply();

        // B again, now that A's lines are committed: refused, nothing written.
        $refused = onConnection('pgsql_b', function () use ($customer) {
            try {
                DB::transaction(fn () => app(PostLedgerEntryAction::class)->handle(holdEntry($customer, '700')));

                return false;
            } catch (DomainApiException $e) {
                return $e->errorCode === 'insufficient_funds';
            }
        });
        DatabaseActor::reapply();

        $wallet = DB::selectOne('SELECT available::text AS a, held::text AS h FROM customer_wallet WHERE customer_id = ?', [$customer->customer_id]);

        expect($waited)->toBeTrue()
            ->and($refused)->toBeTrue()
            ->and([$wallet->a, $wallet->h])->toBe(['300.0000', '700.0000']);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
        // Ledger rows have no DELETE policy (by design), so wipe them and put
        // the internal singletons back the way the migration seeds them.
        // topup references ledger_transaction since spec 009 (empty here).
        DB::statement('TRUNCATE customer_notification, topup, ledger_posting, ledger_transaction, account CASCADE');
        DB::table('customer')->where('customer_id', $customer->customer_id)->delete();
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        DB::purge('pgsql_b');
        DB::beginTransaction(); // hand RefreshDatabase a transaction to roll back
    }
});
