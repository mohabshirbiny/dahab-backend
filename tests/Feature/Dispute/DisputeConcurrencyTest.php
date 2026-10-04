<?php

use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Actions\Disputes\Staff\ResolveDisputeAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\ForfeitDepositAction;
use App\Actions\Orders\Staff\AnswerExtensionRequestAction;
use App\Actions\Orders\Staff\HandoverPieceAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Enums\CompensationReason;
use App\Enums\DisputeOutcome;
use App\Enums\DisputeReason;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Models\OrderExtensionRequest;
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
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 SC-005, T049 (the spec 012 technique): connection A holds its
// transaction; B must wait for A's lock; after A commits, B either changes
// nothing or is refused. Exactly one outcome; money moves once.

function dcOn(string $name, Closure $work): mixed
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

function dcBlocked(Closure $attempt): bool
{
    return dcOn('pgsql_b', function () use ($attempt) {
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

/** @return 'nothing'|'ran'|'refused:<code>' */
function dcAfter(Closure $attempt): string
{
    return dcOn('pgsql_b', function () use ($attempt) {
        try {
            return DB::transaction($attempt) === null ? 'nothing' : 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

function dcAs(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
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

function dcEntries(Order $order, string $kind): int
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')
        ->where(fn ($q) => $q->where('order_id', $order->order_id)->orWhere('buy_request_id', $order->buy_request_id))
        ->where('event_kind', $kind)->count());
}

function dcState(Order $order): string
{
    return DatabaseActor::elevate('maintenance', fn () => Order::query()->find($order->order_id)->state->value);
}

function dcCommitted(Closure $build, Closure $race): void
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

function dcOpen(Customer $by, Order $order): Closure
{
    return fn () => dcAs('customer', $by->customer_id, null, fn () => app(OpenDisputeAction::class)
        ->handle($by, $order->order_id, DisputeReason::OTHER, 'Something is wrong with this order.', []));
}

it('lets the dispute or the no-pay sweep win, never both', function () {
    dcCommitted(function () {
        $order = Disputes::awaitingBalance($this);

        return [$order->refresh(), Orders::buyer($order)];
    }, function (Order $order, Customer $buyer) {
        $system = Staff::query()->findOrFail(SystemActor::id());
        CarbonImmutable::setTestNow($order->balance_due_deadline->subMinute());

        DB::beginTransaction();
        dcOpen($buyer, $order)();

        CarbonImmutable::setTestNow($order->balance_due_deadline->addMinute());
        $forfeit = fn () => dcAs('system', null, null, fn () => app(ForfeitDepositAction::class)->handle($system, $order->order_id));
        $waited = dcBlocked(fn () => DB::transaction($forfeit));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter($forfeit))->toBe('nothing')
            ->and(dcState($order))->toBe('disputed')
            ->and(dcEntries($order, 'deposit_forfeit'))->toBe(0);
    });
});

it('lets the dispute freeze before the payment, which is then refused', function () {
    dcCommitted(function () {
        $order = Disputes::awaitingBalance($this);

        return [$order->refresh(), Orders::buyer($order), Orders::seller($order)];
    }, function (Order $order, Customer $buyer, Customer $seller) {
        DB::beginTransaction();
        dcOpen($seller, $order)();

        $pay = fn () => dcAs('customer', $buyer->customer_id, null, fn () => app(PayBalanceAction::class)->handle($buyer, $order->order_id));
        $waited = dcBlocked(fn () => DB::transaction($pay));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter($pay))->toBe('refused:order_frozen')
            ->and(dcEntries($order, 'balance_payment'))->toBe(0);
    });
});

it('lets the payment go first, and the dispute then freezes a paid order', function () {
    dcCommitted(function () {
        $order = Disputes::awaitingBalance($this);

        return [$order->refresh(), Orders::buyer($order), Orders::seller($order)];
    }, function (Order $order, Customer $buyer, Customer $seller) {
        DB::beginTransaction();
        dcAs('customer', $buyer->customer_id, null, fn () => app(PayBalanceAction::class)->handle($buyer, $order->order_id));

        $waited = dcBlocked(fn () => DB::transaction(dcOpen($seller, $order)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter(dcOpen($seller, $order)))->toBe('ran')
            ->and(dcEntries($order, 'balance_payment'))->toBe(1)
            ->and(DatabaseActor::elevate('maintenance', fn () => Dispute::query()->sole()->frozen_from->value))->toBe('ready_to_collect');
    });
});

it('lets one party\'s dispute win when both open at once', function () {
    dcCommitted(function () {
        $order = Disputes::awaitingBalance($this);

        return [$order->refresh(), Orders::buyer($order), Orders::seller($order)];
    }, function (Order $order, Customer $buyer, Customer $seller) {
        DB::beginTransaction();
        dcOpen($buyer, $order)();

        $waited = dcBlocked(fn () => DB::transaction(dcOpen($seller, $order)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter(dcOpen($seller, $order)))->toBe('refused:order_frozen')
            ->and(DatabaseActor::elevate('maintenance', fn () => Dispute::query()->count()))->toBe(1);
    });
});

it('waits for the resolution before a handover, which then completes once', function () {
    dcCommitted(function () {
        $order = Disputes::readyToCollect($this);
        Disputes::opened($this, $order);

        return [$order->refresh(), Disputes::of($order)];
    }, function (Order $order, Dispute $dispute) {
        $ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();
        $igi = Staff::factory()->role(SeedRole::IGI_BRANCH)->create();
        $code = DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $order->order_id)->sole()->code_encrypted);

        DB::beginTransaction();
        dcAs('staff', null, $ops->staff_id, fn () => app(ResolveDisputeAction::class)
            ->handle($ops, $dispute->dispute_id, DisputeOutcome::RESUME, 'We checked it; collect as planned.'));

        $hand = fn () => dcAs('staff', null, $igi->staff_id, fn () => app(HandoverPieceAction::class)->handle($igi, $order->order_id, $code));
        $waited = dcBlocked(fn () => DB::transaction($hand));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter($hand))->toBe('ran')
            ->and(dcState($order))->toBe('completed')
            ->and(dcAfter($hand))->toBe('refused:illegal_order_transition');
    });
});

it('resolves once when two staff resolve together, refunding once', function () {
    dcCommitted(function () {
        $order = Disputes::awaitingBalance($this);
        Disputes::opened($this, $order);

        return [$order->refresh(), Disputes::of($order)];
    }, function (Order $order, Dispute $dispute) {
        $a = Staff::factory()->role(SeedRole::FINANCE)->create();
        $b = Staff::factory()->role(SeedRole::FINANCE)->create();
        $resolve = fn (Staff $by) => fn () => dcAs('staff', null, $by->staff_id, fn () => app(ResolveDisputeAction::class)
            ->handle($by, $dispute->dispute_id, DisputeOutcome::AGAINST_SALE, 'The piece did not match; refunded.'));

        DB::beginTransaction();
        $resolve($a)();

        $waited = dcBlocked(fn () => DB::transaction($resolve($b)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter($resolve($b)))->toBe('refused:illegal_dispute_transition')
            ->and(dcEntries($order, 'deposit_release'))->toBe(1)
            ->and(dcState($order))->toBe('cancelled_inspection');
    });
});

it('holds one Finance member\'s daily cap across two concurrent payments', function () {
    dcCommitted(function () {
        DatabaseActor::elevate('maintenance', fn () => DB::table('setting')
            ->where('setting_key', 'compensation.cap_per_day_egp')->update(['value_numeric' => 3000]));
        $one = Disputes::awaitingBalance($this);
        Disputes::opened($this, $one);
        $two = Disputes::awaitingBalance($this);
        Disputes::opened($this, $two);

        return [Disputes::of($one), Disputes::of($two)];
    }, function (Dispute $first, Dispute $second) {
        $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
        $pay = fn (Dispute $d) => fn () => dcAs('staff', null, $finance->staff_id, fn () => app(ResolveDisputeAction::class)
            ->handle($finance, $d->dispute_id, DisputeOutcome::RESUME, 'Resolved with compensation for the delay.',
                ['party' => 'buyer', 'amount' => '2000', 'reason' => CompensationReason::IGI_DELAY, 'note' => 'IGI kept the piece too long.']));

        DB::beginTransaction();
        $pay($first)();

        // The second payment by the same person waits on their lock, then sees the first.
        $waited = dcBlocked(fn () => DB::transaction($pay($second)));
        DB::commit();
        DatabaseActor::reapply();

        try {
            expect($waited)->toBeTrue()
                ->and(dcAfter($pay($second)))->toBe('refused:compensation_cap_exceeded')
                ->and(DatabaseActor::elevate('maintenance', fn () => (string) DB::table('compensation')->sum('amount')))->toBe('2000.0000');
        } finally {
            // `setting` survives the truncation: put the seeded cap back.
            DatabaseActor::elevate('maintenance', fn () => DB::table('setting')
                ->where('setting_key', 'compensation.cap_per_day_egp')->update(['value_numeric' => 5000]));
        }
    });
});

it('lets the receipt or the accepted request win, never both', function () {
    dcCommitted(function () {
        $order = Orders::accepted($this);
        Disputes::askMoreTime($this, $order)->assertCreated();
        Orders::staff($this, SeedRole::OPERATIONS);

        return [$order->refresh(), DatabaseActor::elevate('maintenance', fn () => OrderExtensionRequest::query()->sole())];
    }, function (Order $order, OrderExtensionRequest $request) {
        $ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();

        DB::beginTransaction();
        dcAs('staff', null, $ops->staff_id, fn () => app(ReceivePieceAction::class)->handle($ops, $order->order_id));

        $accept = fn () => dcAs('staff', null, $ops->staff_id, fn () => app(AnswerExtensionRequestAction::class)
            ->accept($ops, $request->request_id, 12, 'Extended — please come tomorrow.'));
        $waited = dcBlocked(fn () => DB::transaction($accept));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(dcAfter($accept))->toBe('refused:illegal_extension_request_transition')
            ->and(dcState($order))->toBe('at_inspection')
            ->and(DatabaseActor::elevate('maintenance', fn () => OrderExtensionRequest::query()->sole()->state->value))->toBe('lapsed');
    });
});
