<?php

use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Enums\BuyRequestState;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class)->group('perf');

// Spec 011 plan "Performance Goals" (task T053), opt-in: `php vendor/bin/pest
// --group=perf` (excluded from the default run in phpunit.xml). A send answers
// in < 300 ms p95 with 50 buyers already in line; one sweep releases 1,000 due
// requests in under a minute. Every request is made through the real send
// (hold on the ledger, place in line); the test's transaction rolls it back.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    config(['dahab-buy-requests.send_per_minute' => 100000]);
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

/** @return list<Customer> */
function perfBuyers(int $count, string $amount): array
{
    return array_map(fn () => BuyRequests::funded($amount), range(1, $count));
}

it('sends a request in under 300 ms p95 with 50 buyers already in line', function () {
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $buyers = perfBuyers(80, '50000');

    // 50 in line first.
    foreach (array_slice($buyers, 0, 50) as $buyer) {
        BuyRequests::send($this, $buyer, $listing)->assertCreated();
    }

    $price = BuyRequests::price($this, $listing);
    $times = [];
    foreach (array_slice($buyers, 50) as $buyer) {
        $start = hrtime(true);
        BuyRequests::send($this, $buyer, $listing, ['confirm_locked_price' => $price])->assertCreated();
        $times[] = (hrtime(true) - $start) / 1e6;
    }

    sort($times);
    $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
    fwrite(STDERR, sprintf("\n[perf] send with 50+ in line: median %.0f ms · p95 %.0f ms · max %.0f ms\n", $times[intdiv(count($times), 2)], $p95, end($times)));

    expect($listing->fresh()->active_queue_count)->toBe(80)
        ->and($p95)->toBeLessThan(300);
});

it('releases 1,000 due requests in one sweep in under a minute', function () {
    // Committed for real, as in production: each release is its own
    // transaction. Inside RefreshDatabase's single transaction the thousands
    // of savepoints overflow PostgreSQL's subtransaction cache and every
    // visibility check slows down (measured: ~4x), which is not what runs.
    DB::rollBack();
    DatabaseActor::reapply();
    $customers = [];
    $branchIds = [];

    try {
        Listings::goldPrice();
        $seller = Customer::factory()->verified()->create();
        $buyers = perfBuyers(50, '1000000');
        $customers = [$seller->customer_id, ...array_map(fn (Customer $c) => $c->customer_id, $buyers)];
        $listings = collect(range(1, 20))->map(fn () => DB::transaction(fn () => Listing::factory()->live()->create(['seller_id' => $seller->customer_id])));
        $branchIds = DB::table('listing_branch_option')->pluck('branch_id')->unique()->all();
        $send = app(SendBuyRequestAction::class);
        $terms = BuyRequests::termsId();

        foreach ($listings as $listing) {
            $price = (string) app(ListingPricer::class)->quote($listing)->currentPrice;

            foreach ($buyers as $buyer) {
                DatabaseActor::push('customer', customerId: $buyer->customer_id);
                try {
                    $send->handle($buyer, $listing->listing_id, $price, $terms);
                } finally {
                    DatabaseActor::pop();
                }
            }
        }

        expect(BuyRequest::query()->where('state', BuyRequestState::QUEUED->value)->count())->toBe(1000);
        DB::statement('ANALYZE buy_request, listing, ledger_transaction, ledger_posting');

        $this->travel(49)->hours();
        $start = hrtime(true);
        $this->artisan('buy-requests:expire')->assertSuccessful();
        $seconds = (hrtime(true) - $start) / 1e9;
        fwrite(STDERR, sprintf("\n[perf] sweep of 1,000 due requests: %.1f s\n", $seconds));

        DatabaseActor::elevate('maintenance', function () use ($listings) {
            expect(BuyRequest::query()->where('state', BuyRequestState::RELEASED_EXPIRED->value)->count())->toBe(1000)
                ->and(DB::table('ledger_transaction')->where('event_kind', 'deposit_release')->count())->toBe(1000)
                ->and(Listing::query()->whereKey($listings->pluck('listing_id')->all())->where('state', 'live')->count())->toBe(20);
        });
        expect($seconds)->toBeLessThan(60);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reset();
        DatabaseActor::push('maintenance');
        // TRUNCATE skips the row-level no-delete triggers (same clean-up as BuyRequestCommitTest).
        DB::statement('TRUNCATE "order", buy_request, agreement_acceptance, listing_state_change, listing_queue_seq, listing_ownership_declaration, listing_branch_option, listing_media, listing, gold_price, ledger_posting, ledger_transaction, account, audit_log CASCADE');
        DB::table('branch_hours')->whereIn('branch_id', $branchIds)->delete();
        DB::table('branch')->whereIn('branch_id', $branchIds)->delete();
        DB::table('customer')->whereIn('customer_id', $customers)->delete();
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        DB::beginTransaction(); // hand RefreshDatabase a transaction to roll back
    }
});
