<?php

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class)->group('perf');

// Spec 012 plan "Performance Goals" (task T079), opt-in: `php vendor/bin/pest
// --group=perf` (excluded from the default run in phpunit.xml). The staff list
// answers in < 300 ms over 10,000 orders; pay-balance in < 300 ms p95; one sweep
// pass handles 1,000 due orders in under a minute. Every order is made through
// the real send and accept (the deposit held, the order opened), committed as
// in production, and removed at the end. PERF_ORDERS overrides the 10,000.

/**
 * `$count` accepted orders, committed: one listing each, `$buyers` buyers in
 * turn, one seller.
 *
 * @return list<string> order ids
 */
function perfAcceptedOrders(int $count, int $buyers = 100): array
{
    Listings::goldPrice();
    $seller = Customer::factory()->verified()->create();
    $people = array_map(fn () => BuyRequests::funded('100000000'), range(1, $buyers));
    $send = app(SendBuyRequestAction::class);
    $accept = app(AcceptBuyRequestAction::class);
    $terms = BuyRequests::termsId();
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $listing = DB::transaction(fn () => Listing::factory()->live()->create(['seller_id' => $seller->customer_id]));
        $buyer = $people[$i % $buyers];
        $price = (string) app(ListingPricer::class)->quote($listing)->currentPrice;

        DatabaseActor::push('customer', customerId: $buyer->customer_id);
        try {
            $request = $send->handle($buyer, $listing->listing_id, $price, $terms);
        } finally {
            DatabaseActor::pop();
        }

        $branch = (int) DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->value('branch_id');
        DatabaseActor::push('customer', customerId: $seller->customer_id);
        try {
            $ids[] = $accept->handle($seller, $listing->listing_id, $request->buy_request_id, $branch)['order']->order_id;
        } finally {
            DatabaseActor::pop();
        }
    }
    DB::statement('ANALYZE "order", listing, buy_request, order_state_change, ledger_transaction, ledger_posting, customer');

    return $ids;
}

/** Leave RefreshDatabase's transaction, run `$work` committed, then remove every committed row. */
function perfCommitted(Closure $work): void
{
    Storage::fake('identity_private');
    Notification::fake();
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
                'order_transition', 'piece_type', 'setting', 'staff', 'branch'];
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

it('lists the open orders in under 300 ms over 10,000 orders', function () {
    perfCommitted(function () {
        $count = (int) (getenv('PERF_ORDERS') ?: 10000);
        $build = hrtime(true);
        perfAcceptedOrders($count);
        fwrite(STDERR, sprintf("\n[perf] built %d orders in %.0f s\n", $count, (hrtime(true) - $build) / 1e9));

        Orders::staff($this, SeedRole::OPERATIONS);
        $this->getJson(Orders::STAFF_URL)->assertOk(); // warm up
        $times = [];
        foreach (range(1, 20) as $_) {
            $start = hrtime(true);
            $this->getJson(Orders::STAFF_URL.'?per_page=25')->assertOk();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $p95 = $times[18];
        fwrite(STDERR, sprintf("[perf] GET /dashboard/orders over %d: median %.0f ms · p95 %.0f ms\n", $count, $times[10], $p95));

        expect($p95)->toBeLessThan(300);
    });
});

it('pays a balance in under 300 ms p95', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $orders = collect(range(1, 41))->map(fn () => Orders::inspected($this, Orders::accepted($this), '10.000'));
    $warm = $orders->shift();
    Orders::pay($this, Orders::buyer($warm), $warm)->assertOk(); // warm up

    $times = [];
    foreach ($orders as $order) {
        $buyer = Orders::buyer($order);
        $start = hrtime(true);
        Orders::pay($this, $buyer, $order)->assertOk();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
    fwrite(STDERR, sprintf("\n[perf] pay-balance: median %.0f ms · p95 %.0f ms\n", $times[20], $p95));

    expect($p95)->toBeLessThan(300);
});

it('sweeps 1,000 due orders in one pass in under a minute', function () {
    perfCommitted(function () {
        $ids = perfAcceptedOrders(1000);
        $due = Order::query()->whereKey($ids[0])->value('reach_branch_deadline');

        $this->travelTo(Carbon::parse($due)->addHours(72));
        $start = hrtime(true);
        Orders::sweep();
        $seconds = (hrtime(true) - $start) / 1e9;
        fwrite(STDERR, sprintf("\n[perf] sweep of 1,000 due orders: %.1f s\n", $seconds));

        expect(DatabaseActor::elevate('maintenance', fn () => Order::query()->where('state', OrderState::CANCELLED_SELLER->value)->count()))->toBe(1000)
            ->and($seconds)->toBeLessThan(60);
    });
});
