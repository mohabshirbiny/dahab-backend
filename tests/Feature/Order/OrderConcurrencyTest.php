<?php

use App\Actions\Orders\CancelAcceptanceAction;
use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\DecideAdjustmentAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\ForfeitDepositAction;
use App\Actions\Orders\Staff\HandoverPieceAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCollection;
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
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Spec 012 T073, research R5: two writers on the same order — receive against
// the reach sweep, the seller's cancel against receive, pay against the no-pay
// sweep, a decision against the decision sweep, two handovers with one code,
// staff cancel against the seller's cancel. Connection A holds its transaction
// open; B must wait for A's row lock; after A commits, B changes nothing.
// Exactly one wins and money moves once. Same technique as TopUpConcurrencyTest:
// the fixtures are committed and removed at the end.

function orderOnConnection(string $name, Closure $work): mixed
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
function orderBlockedWhileAHolds(Closure $attempt): bool
{
    return orderOnConnection('pgsql_b', function () use ($attempt) {
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

/** @return 'nothing'|'refused:<code>'|'ran' — B after A committed. */
function orderAfterACommitted(Closure $attempt): string
{
    return orderOnConnection('pgsql_b', function () use ($attempt) {
        try {
            return $attempt() === null ? 'nothing' : 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

/** Work as a customer / staff / the system on the current connection. */
function asOrderActor(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
{
    if ($scope === 'system') {
        return DatabaseActor::elevate('system', $work);
    }
    DatabaseActor::push($scope, customerId: $customerId, staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

/** How many ledger entries of `$kind` the order has. */
function orderEntries(Order $order, string $kind): int
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')
        ->where('order_id', $order->order_id)->where('event_kind', $kind)->count());
}

/**
 * Commit an order (and everything behind it) outside RefreshDatabase's
 * transaction, run the race, then remove every committed row: the database
 * goes back to what the migrations left.
 */
function withCommittedOrder(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();
    Storage::fake('identity_private');
    Notification::fake();

    try {
        Listings::goldPrice();
        // One transaction for the fixtures: the deferred history checks need a commit, not a bare insert.
        $fixtures = DB::transaction(fn () => $build());
        // Each racer acts by itself: no principal left signed in by the fixtures.
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

/** An accepted order, committed: [order, buyer, seller]. */
function committedAccepted(TestCase $test): array
{
    $order = Orders::accepted($test, null, '150000');

    return [$order, Orders::buyer($order), Orders::seller($order)];
}

it('lets receive or the reach sweep win, never both', function () {
    withCommittedOrder(fn () => committedAccepted($this), function (Order $order) {
        $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
        $system = Staff::query()->findOrFail(SystemActor::id());
        CarbonImmutable::setTestNow($order->reach_branch_deadline->subMinute());
        $receive = fn () => app(ReceivePieceAction::class)->handle($staff, $order->order_id);

        DB::beginTransaction();
        asOrderActor('staff', null, $staff->staff_id, $receive);

        CarbonImmutable::setTestNow($order->reach_branch_deadline->addMinute());
        $sweep = fn () => asOrderActor('system', null, null, fn () => app(CancelOrderBySellerAction::class)->byDeadline($system, $order->order_id));
        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($sweep));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($sweep))->toBe('nothing')
            ->and(DatabaseActor::elevate('maintenance', fn () => Order::query()->find($order->order_id)->state->value))->toBe('at_inspection')
            ->and(orderEntries($order, 'deposit_release'))->toBe(0);
    });
});

it('lets the seller cancel or receive win, never both', function () {
    withCommittedOrder(fn () => committedAccepted($this), function (Order $order, Customer $buyer, Customer $seller) {
        $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
        $cancel = fn () => asOrderActor('customer', $seller->customer_id, null, fn () => app(CancelOrderBySellerAction::class)->bySeller($seller, $order->order_id));

        DB::beginTransaction();
        $cancel();

        $receive = fn () => asOrderActor('staff', null, $staff->staff_id, fn () => app(ReceivePieceAction::class)->handle($staff, $order->order_id));
        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($receive));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($receive))->toBe('refused:illegal_order_transition')
            ->and(orderEntries($order, 'deposit_release'))->toBe(1);
    });
});

it('lets the payment or the no-pay sweep win, never both', function () {
    withCommittedOrder(function () {
        $order = Orders::inspected($this, Orders::accepted($this, null, '150000'), '10.000');

        return [$order, Orders::buyer($order)];
    }, function (Order $order, Customer $buyer) {
        $system = Staff::query()->findOrFail(SystemActor::id());
        $pay = fn () => asOrderActor('customer', $buyer->customer_id, null, fn () => app(PayBalanceAction::class)->handle($buyer, $order->order_id));

        DB::beginTransaction();
        $pay();

        CarbonImmutable::setTestNow($order->balance_due_deadline->addMinute());
        $forfeit = fn () => asOrderActor('system', null, null, fn () => app(ForfeitDepositAction::class)->handle($system, $order->order_id));
        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($forfeit));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($forfeit))->toBe('nothing')
            ->and(orderEntries($order, 'balance_payment'))->toBe(1)
            ->and(orderEntries($order, 'deposit_forfeit'))->toBe(0);
    });
});

it('lets the buyer decision or the decision sweep win, never both', function () {
    withCommittedOrder(function () {
        $order = Orders::inspected($this, Orders::accepted($this, null, '150000'), '9.500');

        return [$order, Orders::buyer($order)];
    }, function (Order $order, Customer $buyer) {
        $system = Staff::query()->findOrFail(SystemActor::id());
        $inspectionId = DatabaseActor::elevate('maintenance', fn () => DB::table('inspection_result')->where('order_id', $order->order_id)->value('inspection_id'));
        $decide = fn () => asOrderActor('customer', $buyer->customer_id, null,
            fn () => app(DecideAdjustmentAction::class)->byBuyer($buyer, $order->order_id, false, $inspectionId));

        DB::beginTransaction();
        $decide();

        CarbonImmutable::setTestNow($order->decision_due_deadline->addMinute());
        $sweep = fn () => asOrderActor('system', null, null, fn () => app(DecideAdjustmentAction::class)->byDeadline($system, $order->order_id));
        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($sweep));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($sweep))->toBe('nothing')
            ->and(orderEntries($order, 'deposit_release'))->toBe(1)
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('settlement_decision')->where('order_id', $order->order_id)->count()))->toBe(1);
    });
});

it('hands a piece over once when two counters use the same code', function () {
    withCommittedOrder(function () {
        $order = Orders::inspected($this, Orders::accepted($this, null, '150000'), '10.000');
        Orders::pay($this, Orders::buyer($order), $order)->assertOk();

        return [$order->refresh()];
    }, function (Order $order) {
        $a = Staff::factory()->role(SeedRole::IGI_BRANCH)->create();
        $b = Staff::factory()->role(SeedRole::IGI_BRANCH)->create();
        $code = DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $order->order_id)->sole()->code_encrypted);
        $hand = fn (Staff $by) => fn () => asOrderActor('staff', null, $by->staff_id, fn () => app(HandoverPieceAction::class)->handle($by, $order->order_id, $code));

        DB::beginTransaction();
        $hand($a)();

        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($hand($b)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($hand($b)))->toBe('refused:illegal_order_transition')
            ->and(DatabaseActor::elevate('maintenance', fn () => Order::query()->find($order->order_id)->state->value))->toBe('completed');
    });
});

it('lets the staff cancel or the seller cancel win, never both', function () {
    withCommittedOrder(fn () => committedAccepted($this), function (Order $order, Customer $buyer, Customer $seller) {
        $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
        $staffCancel = fn () => asOrderActor('staff', null, $staff->staff_id,
            fn () => app(CancelAcceptanceAction::class)->handle($staff, $order->order_id, 'The piece was damaged before delivery.', true));

        DB::beginTransaction();
        $staffCancel();

        $sellerCancel = fn () => asOrderActor('customer', $seller->customer_id, null, fn () => app(CancelOrderBySellerAction::class)->bySeller($seller, $order->order_id));
        $waited = orderBlockedWhileAHolds(fn () => DB::transaction($sellerCancel));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(orderAfterACommitted($sellerCancel))->toBe('refused:illegal_order_transition')
            ->and(orderEntries($order, 'deposit_release'))->toBe(1);
    });
});
