<?php

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Enums\DisputeReason;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuyRequests;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class)->group('perf');

// Spec 014 plan "Performance goals" (T052), opt-in: `php vendor/bin/pest
// --group=perf`. The Disputes queue answers in < 300 ms over 10,000 disputes;
// opening and resolving answer in < 300 ms p95. Every dispute is opened
// through the real Actions on a received order, committed, and removed at the
// end. PERF_DISPUTES overrides the 10,000.

/** `$count` disputes, committed, each on its own received order. */
function perfDisputes(int $count, int $buyers = 100): void
{
    Listings::goldPrice();
    $seller = Customer::factory()->verified()->create();
    $people = array_map(fn () => BuyRequests::funded('100000000'), range(1, $buyers));
    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    $terms = BuyRequests::termsId();
    $as = function (string $scope, ?string $customer, ?string $staffId, Closure $work) {
        DatabaseActor::push($scope, customerId: $customer, staffId: $staffId);
        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    };

    for ($i = 0; $i < $count; $i++) {
        $listing = DB::transaction(fn () => Listing::factory()->live()->create(['seller_id' => $seller->customer_id]));
        $buyer = $people[$i % $buyers];
        $price = (string) app(ListingPricer::class)->quote($listing)->currentPrice;
        $request = $as('customer', $buyer->customer_id, null, fn () => app(SendBuyRequestAction::class)->handle($buyer, $listing->listing_id, $price, $terms));
        $branch = (int) DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->value('branch_id');
        $order = $as('customer', $seller->customer_id, null, fn () => app(AcceptBuyRequestAction::class)
            ->handle($seller, $listing->listing_id, $request->buy_request_id, $branch)['order']);
        $as('staff', null, $staff->staff_id, fn () => app(ReceivePieceAction::class)->handle($staff, $order->order_id));
        $as('customer', $buyer->customer_id, null, fn () => app(OpenDisputeAction::class)
            ->handle($buyer, $order->order_id, DisputeReason::OTHER, 'Something about this piece is not right.', []));
    }
    DB::statement('ANALYZE dispute, "order", customer, staff');
}

function perfDisputesCommitted(Closure $work): void
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
        DB::beginTransaction();
    }
}

it('lists the Disputes queue in under 300 ms over 10,000 disputes', function () {
    perfDisputesCommitted(function () {
        $count = (int) (getenv('PERF_DISPUTES') ?: 10000);
        $ops = Orders::staff($this, SeedRole::OPERATIONS); // seeds the roles with their codes first
        $build = hrtime(true);
        perfDisputes($count);
        fwrite(STDERR, sprintf("\n[perf] built %d disputes in %.0f s\n", $count, (hrtime(true) - $build) / 1e9));

        Disputes::actAs($ops);
        $this->getJson(Disputes::STAFF_URL)->assertOk();
        $times = [];
        foreach (range(1, 20) as $_) {
            $start = hrtime(true);
            $this->getJson(Disputes::STAFF_URL.'?per_page=25')->assertOk();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        fwrite(STDERR, sprintf("[perf] GET /dashboard/disputes over %d: median %.0f ms · p95 %.0f ms\n", $count, $times[10], $times[18]));

        expect($times[18])->toBeLessThan(300);
    });
});

it('opens and resolves a dispute in under 300 ms p95', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $orders = collect(range(1, 21))->map(fn () => Disputes::awaitingBalance($this));

    $open = [];
    foreach ($orders as $order) {
        $start = hrtime(true);
        Disputes::open($this, Orders::buyer($order), $order)->assertCreated();
        $open[] = (hrtime(true) - $start) / 1e6;
    }
    $resolve = [];
    Orders::staff($this, SeedRole::OPERATIONS);
    foreach ($orders as $order) {
        $dispute = Disputes::of($order);
        $start = hrtime(true);
        Disputes::resolve($this, $dispute)->assertOk();
        $resolve[] = (hrtime(true) - $start) / 1e6;
    }
    array_shift($open);
    array_shift($resolve);
    sort($open);
    sort($resolve);
    fwrite(STDERR, sprintf("\n[perf] open p95 %.0f ms · resolve p95 %.0f ms\n", $open[18], $resolve[18]));

    expect($open[18])->toBeLessThan(300)->and($resolve[18])->toBeLessThan(300);
});
