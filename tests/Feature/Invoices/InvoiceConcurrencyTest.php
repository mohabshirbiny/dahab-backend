<?php

use App\Actions\Invoices\IssueCreditNoteAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Staff;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
use App\Support\Invoices\Documents\TaxDocumentWriter;
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
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 SC-001, SC-003 (the spec 012–015 technique): connection A holds its
// transaction; B must wait for A's lock; after A commits, B either changes
// nothing or is refused. Never two invoices for one party, never a credit
// above the invoice.

function inv016On(string $name, Closure $work): mixed
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

function inv016Blocked(Closure $attempt): bool
{
    return inv016On('pgsql_b', function () use ($attempt) {
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
function inv016After(Closure $attempt): string
{
    return inv016On('pgsql_b', function () use ($attempt) {
        try {
            DB::transaction($attempt);

            return 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        }
    });
}

function inv016Frame(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
{
    DatabaseActor::push($scope, customerId: $customerId, staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

function inv016Committed(Closure $build, Closure $race): void
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

function inv016Pay(Order $order): Closure
{
    return fn () => inv016Frame('customer', $order->buyer_id, null, fn () => app(PayBalanceAction::class)
        ->handle(Customer::query()->findOrFail($order->buyer_id), $order->order_id));
}

function inv016Credit(Staff $staff, string $invoiceId, string $amount): Closure
{
    return fn () => inv016Frame('staff', null, $staff->staff_id, fn () => app(IssueCreditNoteAction::class)
        ->handle($staff, $invoiceId, $amount, 'Correcting the commission charged.'));
}

it('issues one pair of invoices when the balance is paid twice at once', function () {
    inv016Committed(fn () => [Orders::inspected($this, Orders::accepted($this), '10.000')], function (Order $order) {
        DB::beginTransaction();
        inv016Pay($order)();

        $waited = inv016Blocked(fn () => DB::transaction(inv016Pay($order)));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(inv016After(inv016Pay($order)))->toBe('refused:illegal_order_transition')
            ->and(DatabaseActor::elevate('maintenance', fn () => TaxInvoice::query()->where('order_id', $order->order_id)->count()))->toBe(2)
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('order_id', $order->order_id)
                ->where('event_kind', 'balance_payment')->count()))->toBe(1);
    });
});

it('never credits more than the invoice when two credit notes race', function () {
    inv016Committed(function () {
        $order = Invoices::paid($this);

        return [Invoices::of($order, 'seller'), Staff::factory()->role(SeedRole::FINANCE)->create()];
    }, function (TaxInvoice $invoice, Staff $finance) {
        DB::beginTransaction();
        inv016Credit($finance, $invoice->invoice_id, '500')();

        $waited = inv016Blocked(fn () => DB::transaction(inv016Credit($finance, $invoice->invoice_id, '500')));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(inv016After(inv016Credit($finance, $invoice->invoice_id, '500')))->toBe('refused:credit_exceeds_invoice')
            ->and(inv016After(inv016Credit($finance, $invoice->invoice_id, '184')))->toBe('ran')
            ->and(DatabaseActor::elevate('maintenance', fn () => (string) CreditNote::query()->sum('gross_amount')))->toBe('684.0000');
    });
});

it('lets a credit note and the document job on the same invoice both finish', function () {
    inv016Committed(function () {
        $order = Invoices::paid($this);

        return [Invoices::of($order, 'seller'), Staff::factory()->role(SeedRole::FINANCE)->create()];
    }, function (TaxInvoice $invoice, Staff $finance) {
        Invoices::configureIssuer();
        Invoices::fakeRenderer();

        DB::beginTransaction();
        inv016Credit($finance, $invoice->invoice_id, '100')();

        $render = fn () => inv016Frame('system', null, SystemActor::id(), fn () => app(TaxDocumentWriter::class)->invoice($invoice->invoice_id));
        $waited = inv016Blocked(fn () => DB::transaction($render));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(inv016After($render))->toBe('ran')
            ->and(DatabaseActor::elevate('maintenance', fn () => TaxInvoice::query()->whereKey($invoice->invoice_id)->value('storage_ref')))->not->toBeNull()
            ->and(DatabaseActor::elevate('maintenance', fn () => CreditNote::query()->count()))->toBe(1);
    });
});
