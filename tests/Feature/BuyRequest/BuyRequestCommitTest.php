<?php

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\LeaveQueueAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Actions\Listings\WithdrawListingAction;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 analysis H1, FR-004, FR-014, US3 scenario 9: real commits. The
// deferred checks (history row, deposit entries, listing/queue agreement)
// fire at commit under whatever scope is current then, and two actions on
// one line serialise on the listing lock — exactly one wins. Same technique
// as spec 010's ListingConcurrencyTest: connection A holds its transaction,
// connection B must wait for A's lock; fixtures are committed and removed.

function brOn(string $name, Closure $work): mixed
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

/** Run `$work` as `$customer`, the way a request's middleware binds the scope. */
function brAs(Customer $customer, Closure $work): mixed
{
    DatabaseActor::push('customer', $customer->customer_id);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

/** B's attempt must first block on A's lock (55P03 with a short lock_timeout). */
function brBlocked(Closure $attempt): bool
{
    return brOn('pgsql_b', function () use ($attempt) {
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

/** @return string 'ran' or the refusal code — B after A committed. */
function brAfter(Closure $attempt): string
{
    return brOn('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return $e->errorCode;
        }
    });
}

function brPrice(Listing $listing): string
{
    return (string) app(ListingPricer::class)->quote($listing->fresh())->currentPrice;
}

function brSend(Customer $buyer, Listing $listing): BuyRequest
{
    $price = brPrice($listing);
    $terms = BuyRequests::termsId();

    return brAs($buyer, fn () => app(SendBuyRequestAction::class)->handle($buyer, $listing->listing_id, $price, $terms));
}

function brCommitted(Closure $test): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack(); // leave RefreshDatabase's transaction: B must see committed rows
    DatabaseActor::reapply();

    $customers = [];
    $branchIds = [];

    try {
        Listings::goldPrice();
        $seller = Customer::factory()->verified()->create();
        $alice = Customer::factory()->verified()->create();
        $bob = Customer::factory()->verified()->create();
        $customers = [$seller->customer_id, $alice->customer_id, $bob->customer_id];
        Ledger::topUp($alice, '20000');
        Ledger::topUp($bob, '20000');
        $listing = DB::transaction(fn () => Listing::factory()->live()->create(['seller_id' => $seller->customer_id]));
        $branchIds = DB::table('listing_branch_option')->pluck('branch_id')->unique()->all();

        $test($seller, $alice, $bob, $listing);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reset();
        DatabaseActor::push('maintenance');
        // TRUNCATE skips the row-level no-delete triggers.
        DB::statement('TRUNCATE customer_notification, "order", buy_request, agreement_acceptance, listing_state_change, listing_queue_seq, listing_ownership_declaration, listing_branch_option, listing_media, listing, gold_price, ledger_posting, ledger_transaction, account, audit_log CASCADE');
        DB::table('branch_hours')->whereIn('branch_id', $branchIds)->delete();
        DB::table('branch')->whereIn('branch_id', $branchIds)->delete();
        DB::table('customer')->whereIn('customer_id', $customers)->delete();
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        DB::purge('pgsql_b');
        DB::beginTransaction(); // hand RefreshDatabase a transaction to roll back
    }
}

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

it('commits a join for real, the deferred checks passing inside the queue scope', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        $request = brSend($alice, $listing);

        expect(DB::transactionLevel())->toBe(0)
            ->and(BuyRequest::query()->find($request->buy_request_id)?->state)->toBe(BuyRequestState::QUEUED)
            ->and(Listing::query()->find($listing->listing_id)->state)->toBe(ListingState::RESERVED)
            ->and(BuyRequests::netHeld($request))->toBe(bcadd((string) $request->deposit_amount, '0', 4));
    });
});

it('commits even when the outer transaction ends in the plain customer scope (analysis H1)', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        // The buyer's customer frame opens the transaction; the queue work nests inside it
        // (a savepoint); the COMMIT — and every deferred trigger — runs with only the
        // customer scope set. The triggers read under a scope they set themselves.
        $price = brPrice($listing);
        $terms = BuyRequests::termsId();
        brAs($alice, function () use ($alice, $listing, $price, $terms) {
            DB::beginTransaction();
            app(SendBuyRequestAction::class)->handle($alice, $listing->listing_id, $price, $terms);
            expect(DatabaseActor::scope())->toBe('customer');
            DB::commit();
        });

        $request = BuyRequest::query()->where('buyer_id', $alice->customer_id)->sole();
        expect($request->state)->toBe(BuyRequestState::QUEUED)
            ->and(Listing::query()->find($listing->listing_id)->state)->toBe(ListingState::RESERVED);

        // And a leave that empties the line, committed the same way.
        brAs($alice, function () use ($alice, $request) {
            DB::beginTransaction();
            app(LeaveQueueAction::class)->handle($alice, $request->buy_request_id, false);
            DB::commit();
        });

        expect(Listing::query()->find($listing->listing_id)->state)->toBe(ListingState::LIVE)
            ->and(BuyRequests::netHeld($request))->toBe('0.0000');
    });
});

it('refuses at commit a listing left live with someone in line', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        $request = brSend($alice, $listing);

        // Force the state back to live with its history row but leave the request queued.
        $failed = null;
        try {
            DB::transaction(function () use ($listing) {
                DB::table('listing')->where('listing_id', $listing->listing_id)->update(['state' => 'live']);
                DB::table('listing_state_change')->insert([
                    'listing_id' => $listing->listing_id, 'from_state' => 'reserved', 'to_state' => 'live',
                    'actor_staff_id' => SystemActor::id(),
                ]);
            });
        } catch (QueryException|PDOException $e) {
            // Raised by COMMIT itself: the deferred trigger fires there.
            $failed = $e->errorInfo[0] ?? null;
        }

        expect($failed)->toBe('DH004')
            ->and(Listing::query()->find($listing->listing_id)->state)->toBe(ListingState::RESERVED)
            ->and($request->fresh()->state)->toBe(BuyRequestState::QUEUED);
    });
});

it('serialises two joins on the listing lock and gives them distinct places', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        $price = (string) app(ListingPricer::class)->quote($listing->fresh())->currentPrice;

        brAs($alice, function () use ($alice, $listing, $price) {
            DB::beginTransaction(); // A joins and keeps the listing lock
            app(SendBuyRequestAction::class)->handle($alice, $listing->listing_id, $price, BuyRequests::termsId());
        });

        $waited = brBlocked(fn () => brAs($bob, fn () => app(SendBuyRequestAction::class)->handle($bob, $listing->listing_id, $price, BuyRequests::termsId())));

        DB::commit();
        DatabaseActor::reapply();

        $joined = brAfter(fn () => brAs($bob, fn () => app(SendBuyRequestAction::class)->handle($bob, $listing->listing_id, $price, BuyRequests::termsId())));
        DatabaseActor::reapply();

        $positions = BuyRequest::query()->orderBy('queue_position')->pluck('queue_position')->all();
        expect($waited)->toBeTrue()
            ->and($joined)->toBe('ran')
            ->and($positions)->toBe([1, 2])
            ->and(Listing::query()->find($listing->listing_id)->active_queue_count)->toBe(2);
    });
});

it('lets exactly one of an accept and the head\'s leave win, and money follows the winner', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        $head = brSend($alice, $listing);
        $second = brSend($bob, $listing);
        $branch = BuyRequests::branchOf($listing);

        brAs($seller, function () use ($seller, $listing, $head, $branch) {
            DB::beginTransaction(); // A: the seller accepts and keeps the lock
            app(AcceptBuyRequestAction::class)->handle($seller, $listing->listing_id, $head->buy_request_id, $branch);
        });

        $waited = brBlocked(fn () => brAs($alice, fn () => app(LeaveQueueAction::class)->handle($alice, $head->buy_request_id, false)));

        DB::commit();
        DatabaseActor::reapply();

        $leave = brAfter(fn () => brAs($alice, fn () => app(LeaveQueueAction::class)->handle($alice, $head->buy_request_id, false)));
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and($leave)->toBe('not_in_queue')
            ->and($head->fresh()->state)->toBe(BuyRequestState::ACCEPTED)
            ->and($second->fresh()->state)->toBe(BuyRequestState::RELEASED_NOT_CHOSEN)
            ->and(BuyRequests::netHeld($head))->toBe(bcadd((string) $head->deposit_amount, '0', 4))
            ->and(BuyRequests::netHeld($second))->toBe('0.0000')
            ->and(Order::query()->count())->toBe(1);
    });
});

it('lets exactly one of a join and the seller\'s withdrawal win', function () {
    brCommitted(function (Customer $seller, Customer $alice, Customer $bob, Listing $listing) {
        $price = (string) app(ListingPricer::class)->quote($listing->fresh())->currentPrice;

        brAs($seller, function () use ($seller, $listing) {
            DB::beginTransaction(); // A: the seller withdraws and keeps the lock
            app(WithdrawListingAction::class)->handle($seller, $listing->listing_id);
        });

        $waited = brBlocked(fn () => brAs($alice, fn () => app(SendBuyRequestAction::class)->handle($alice, $listing->listing_id, $price, BuyRequests::termsId())));

        DB::commit();
        DatabaseActor::reapply();

        $join = brAfter(fn () => brAs($alice, fn () => app(SendBuyRequestAction::class)->handle($alice, $listing->listing_id, $price, BuyRequests::termsId())));
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and($join)->toBe('listing_not_purchasable')
            ->and(BuyRequest::query()->count())->toBe(0)
            ->and(BuyRequests::balances($alice))->toBe(['available' => '20000.0000', 'held' => '0.0000']);
    });
});
