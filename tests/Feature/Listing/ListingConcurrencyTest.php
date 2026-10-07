<?php

use App\Actions\Listings\DecideListingAction;
use App\Actions\Listings\WithdrawListingAction;
use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Spec 010 FR-015, SC-006, US2 scenario 5: two staff deciding on the same
// listing, or a staff take-down racing the seller's withdrawal — exactly one
// wins. Same technique as spec 009's TopUpConcurrencyTest: connection A holds
// its transaction open, connection B must wait for A's row lock; after A
// commits, B is refused. Fixtures are committed and removed at the end.

function listingOnConnection(string $name, Closure $work): mixed
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
function listingBlockedWhileAHoldsTheRow(Closure $attempt): bool
{
    return listingOnConnection('pgsql_b', function () use ($attempt) {
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

/** @return 'refused'|'ran'|string — B after A committed. */
function listingAfterACommitted(Closure $attempt): string
{
    return listingOnConnection('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return $e->errorCode === 'illegal_listing_transition' ? 'refused' : 'other:'.$e->errorCode;
        }
    });
}

function withCommittedListings(Closure $test): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack(); // leave RefreshDatabase's transaction: B must see committed rows
    DatabaseActor::reapply();

    $seller = $staff = $other = $waiting = $live = null;
    $branchIds = [];

    try {
        // One transaction per listing: outside a transaction each statement commits on its own,
        // and a listing committed before its history row is refused (trg_listing_change_recorded).
        $seller = Customer::factory()->verified()->create();
        // No roles: a role row created here would be committed and outlive the test.
        $staff = Staff::factory()->withRole()->create();
        $other = Staff::factory()->withRole()->create();
        $waiting = DB::transaction(fn () => Listing::factory()->inReview()->create(['seller_id' => $seller->customer_id]));
        $live = DB::transaction(fn () => Listing::factory()->live()->create(['seller_id' => $seller->customer_id]));
        $branchIds = DB::table('listing_branch_option')->pluck('branch_id')->unique()->all();

        $test($seller, $staff, $other, $waiting, $live);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
        // TRUNCATE skips the row-level no-delete triggers.
        // The customer's wallet accounts go too; the ledger singletons are re-provisioned below.
        DB::statement('TRUNCATE customer_notification, listing_state_change, listing_queue_seq, listing_ownership_declaration, listing_branch_option, listing_media, listing, ledger_posting, ledger_transaction, account, audit_log CASCADE');
        DB::table('branch_hours')->whereIn('branch_id', $branchIds)->delete();
        DB::table('branch')->whereIn('branch_id', $branchIds)->delete();
        DB::table('customer')->where('customer_id', $seller?->customer_id)->delete();
        DB::table('staff')->whereIn('staff_id', array_filter([$staff?->staff_id, $other?->staff_id]))->delete();
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

it('lets exactly one of two staff decisions take a waiting listing', function () {
    withCommittedListings(function (Customer $seller, Staff $staff, Staff $other, Listing $waiting) {
        $decide = fn (Staff $by, ListingDecision $d, ?string $note = null) => app(DecideListingAction::class)->handle($by, $waiting->listing_id, $d, $note);

        DB::beginTransaction(); // A approves and keeps its lock
        $decide($staff, ListingDecision::APPROVED);

        $waited = listingBlockedWhileAHoldsTheRow(fn () => DB::transaction(fn () => $decide($other, ListingDecision::CHANGES_REQUESTED, 'Please add a clearer hallmark photo.')));

        DB::commit();
        DatabaseActor::reapply();

        $sendBack = listingAfterACommitted(fn () => $decide($other, ListingDecision::CHANGES_REQUESTED, 'Please add a clearer hallmark photo.'));
        $reject = listingAfterACommitted(fn () => $decide($other, ListingDecision::REJECTED, 'The photos are not of this piece.'));
        $again = listingAfterACommitted(fn () => $decide($other, ListingDecision::APPROVED));
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and($sendBack)->toBe('refused')
            ->and($reject)->toBe('refused')
            ->and($again)->toBe('refused')
            ->and(Listing::query()->find($waiting->listing_id)->state)->toBe(ListingState::LIVE)
            ->and(DB::table('listing_state_change')->where('listing_id', $waiting->listing_id)->where('from_state', 'in_review')->count())->toBe(1)
            ->and(DB::table('listing_state_change')->where('listing_id', $waiting->listing_id)->where('from_state', 'in_review')->value('actor_staff_id'))->toBe($staff->staff_id)
            ->and(DB::table('audit_log')->where('entity_type', 'listing')->where('entity_id', $waiting->listing_id)->count())->toBe(1);
    });
});

it('lets exactly one of a seller withdrawal and a staff take-down win', function () {
    withCommittedListings(function (Customer $seller, Staff $staff, Staff $other, Listing $waiting, Listing $live) {
        $withdraw = fn () => DatabaseActor::elevate('maintenance', fn () => app(WithdrawListingAction::class)->handle($seller, $live->listing_id));
        $takeDown = fn () => app(DecideListingAction::class)->handle($staff, $live->listing_id, ListingDecision::TAKEN_DOWN, 'Reported by a buyer as misdescribed.');

        DB::beginTransaction(); // A: the seller withdraws and keeps the lock
        $withdraw();

        $waited = listingBlockedWhileAHoldsTheRow(fn () => DB::transaction($takeDown));

        DB::commit();
        DatabaseActor::reapply();

        $after = listingAfterACommitted($takeDown);
        DatabaseActor::reapply();

        $moves = DB::table('listing_state_change')->where('listing_id', $live->listing_id)->where('to_state', 'withdrawn')->get();

        expect($waited)->toBeTrue()
            ->and($after)->toBe('refused')
            ->and(Listing::query()->find($live->listing_id)->state)->toBe(ListingState::WITHDRAWN)
            ->and($moves)->toHaveCount(1)
            ->and($moves[0]->actor_customer_id)->toBe($seller->customer_id)
            ->and(DB::table('audit_log')->where('action', 'listing.taken_down')->count())->toBe(0);
    });
});

it('commits a real move with its history row, and the database accepts it', function () {
    withCommittedListings(function (Customer $seller, Staff $staff, Staff $other, Listing $waiting) {
        // Outside a test transaction the deferred "every move is recorded" trigger fires at COMMIT.
        app(DecideListingAction::class)->handle($staff, $waiting->listing_id, ListingDecision::APPROVED);

        $unrecorded = null;
        try {
            DB::transaction(fn () => DB::table('listing')->where('listing_id', $waiting->listing_id)->update(['state' => 'withdrawn']));
        } catch (PDOException $e) {
            // Raised by COMMIT itself, so it is a plain PDOException, not a QueryException.
            $unrecorded = (string) $e->getCode();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();

        expect(Listing::query()->find($waiting->listing_id)->state)->toBe(ListingState::LIVE)
            ->and($unrecorded)->toBe('DH004');
    });
});
