<?php

use App\Actions\Orders\Customer\FreeRelistAction;
use App\Actions\Orders\Customer\RateOrderAction;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderRating;
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
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 T019 and T030, research R5: two writers on the same collected
// order — two relists, two ratings by one party. Connection A holds its
// transaction open; B must wait for A's row lock; after A commits, B changes
// nothing and is refused. Same technique as OrderConcurrencyTest: the
// fixtures are committed and removed at the end.

function ac018OnConnection(string $name, Closure $work): mixed
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
function ac018BlockedWhileAHolds(Closure $attempt): bool
{
    return ac018OnConnection('pgsql_b', function () use ($attempt) {
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

/** @return 'ran'|'refused:<code>' — B after A committed. */
function ac018AfterACommitted(Closure $attempt): string
{
    return ac018OnConnection('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

function ac018AsCustomer(string $customerId, Closure $work): mixed
{
    DatabaseActor::push('customer', customerId: $customerId, staffId: null);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

/** Commit a collected order, run the race, then remove every committed row. */
function ac018WithCommittedOrder(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();
    Storage::fake('identity_private');
    Notification::fake();

    try {
        Listings::goldPrice();
        $fixtures = DB::transaction(fn () => $build());
        Auth::forgetGuards();
        app()->forgetInstance(RequestContext::class);
        $race(...$fixtures);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        CarbonImmutable::setTestNow();
        DatabaseActor::reapply();
        DatabaseActor::elevate('maintenance', function () {
            $keep = ['migrations', 'karat', 'karat_price_adjustment', 'legal_document', 'listing_transition', 'buy_request_transition',
                'order_transition', 'withdrawal_transition', 'payout_account_transition', 'dispute_transition', 'extension_request_transition', 'piece_type', 'setting', 'staff', 'branch'];
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

it('lets one of two parallel relists of the same order win, never both', function () {
    ac018WithCommittedOrder(
        function () {
            $order = FreeRelists::ac018CollectedOrder($this);

            return [$order, Orders::buyer($order)];
        },
        function (Order $order, $buyer) {
            $data = FreeRelists::ac018RelistPayload($order);
            $relist = fn () => ac018AsCustomer($buyer->customer_id, fn () => app(FreeRelistAction::class)->handle($buyer, $order->order_id, $data));

            DB::beginTransaction();
            $relist();

            $waited = ac018BlockedWhileAHolds(fn () => DB::transaction($relist));
            DB::commit();
            DatabaseActor::reapply();

            expect($waited)->toBeTrue()
                ->and(ac018AfterACommitted($relist))->toBe('refused:already_relisted')
                ->and(DatabaseActor::elevate('maintenance', fn () => Listing::query()->where('relisted_from_order_id', $order->order_id)->count()))->toBe(1);
        },
    );
});

it('lets one of two parallel ratings by the same party win, never both', function () {
    ac018WithCommittedOrder(
        function () {
            $order = FreeRelists::ac018CollectedOrder($this);

            return [$order, Orders::buyer($order)];
        },
        function (Order $order, $buyer) {
            $rate = fn () => ac018AsCustomer($buyer->customer_id, fn () => app(RateOrderAction::class)->handle($buyer, $order->order_id, 5, null));

            DB::beginTransaction();
            $rate();

            $waited = ac018BlockedWhileAHolds(fn () => DB::transaction($rate));
            DB::commit();
            DatabaseActor::reapply();

            expect($waited)->toBeTrue()
                ->and(ac018AfterACommitted($rate))->toBe('refused:already_rated')
                ->and(DatabaseActor::elevate('maintenance', fn () => OrderRating::query()->where('order_id', $order->order_id)->count()))->toBe(1);
        },
    );
});
