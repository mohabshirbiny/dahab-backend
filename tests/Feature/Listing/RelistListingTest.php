<?php

use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Notifications\ListingDecisionNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Settings page and relist: a piece taken down off the market goes back live — the seller's own
// take-down from the app, any take-down from the Dashboard. Not a piece that left another way.

const RELIST_REASON = 'The seller sent better photos by email.';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

function sellerWithdraw($test, Customer $seller, Listing $listing)
{
    return Listings::as($test, $seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/withdraw", [], Listings::key());
}

function sellerRelist($test, Customer $seller, Listing $listing)
{
    return Listings::as($test, $seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/relist", [], Listings::key());
}

function staffRelist($test, Listing $listing, array $body = ['reason' => RELIST_REASON])
{
    TopUps::actAsStaff($test, SeedRole::OPERATIONS);

    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/relist", $body, Listings::key());
}

function staffTakeDown($test, Listing $listing)
{
    TopUps::actAsStaff($test, SeedRole::OPERATIONS);

    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/takedown", ['reason' => 'The photos look taken from another listing.'], Listings::key());
}

it('lets the seller bring back a piece they took down', function () {
    sellerWithdraw($this, $this->seller, $this->listing)->assertOk()
        ->assertJsonPath('data.can_relist', true)->assertJsonPath('data.taken_down_by', 'seller');

    sellerRelist($this, $this->seller, $this->listing)->assertOk()
        ->assertJsonPath('data.state', 'live')
        ->assertJsonPath('data.can_relist', false)
        ->assertJsonPath('data.taken_down_by', null)
        ->assertJsonPath('data.can_withdraw', true);

    $change = ListingStateChange::query()->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($change->from_state)->toBe(ListingState::WITHDRAWN)
        ->and($change->to_state)->toBe(ListingState::LIVE)
        ->and($change->actor_customer_id)->toBe($this->seller->customer_id)
        ->and(AuditLog::query()->where('action', 'listing.relisted')->where('entity_id', $this->listing->listing_id)->count())->toBe(1);

    // Back on the market at once.
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertOk();
});

it('keeps a staff take-down for staff to undo', function () {
    staffTakeDown($this, $this->listing)->assertOk()
        ->assertJsonPath('data.can_relist', true)->assertJsonPath('data.taken_down_by', 'staff');

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.can_relist', false)->assertJsonPath('data.taken_down_by', 'staff');
    sellerRelist($this, $this->seller, $this->listing)->assertStatus(409)
        ->assertJsonPath('code', 'listing_not_relistable')->assertJsonPath('details.reason', 'taken_down_by_staff');

    staffRelist($this, $this->listing)->assertOk()->assertJsonPath('data.state', 'live')->assertJsonPath('data.can_take_down', true);

    $audit = AuditLog::query()->where('action', 'listing.relisted')->sole();
    expect($audit->reason)->toBe(RELIST_REASON)
        ->and($audit->before_json)->toBe(['state' => 'withdrawn']);
    Notification::assertSentTo($this->seller, ListingDecisionNotification::class,
        fn (ListingDecisionNotification $n) => $n->decision === ListingDecision::RELISTED && $n->message === RELIST_REASON);
});

it('lets staff bring back a piece the seller took down', function () {
    sellerWithdraw($this, $this->seller, $this->listing)->assertOk();

    staffRelist($this, $this->listing)->assertOk()->assertJsonPath('data.state', 'live');
});

it('asks staff for a reason', function () {
    staffTakeDown($this, $this->listing)->assertOk();

    staffRelist($this, $this->listing, [])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN);
});

it('refuses a piece that is not withdrawn, or left the market another way', function () {
    sellerRelist($this, $this->seller, $this->listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    // A returned piece the seller collected: withdrawn, but never taken down.
    DB::transaction(function () {
        $from = 'live';
        foreach (['reserved', 'accepted', 'awaiting_seller_return', 'withdrawn'] as $to) {
            DB::table('listing')->where('listing_id', $this->listing->listing_id)->update(['state' => $to]);
            ListingStateChange::query()->create([
                'listing_id' => $this->listing->listing_id, 'from_state' => $from, 'to_state' => $to,
                'actor_staff_id' => SystemActor::id(), 'note' => 'note for '.$to,
            ]);
            $from = $to;
        }
    });

    staffRelist($this, $this->listing)->assertStatus(409)
        ->assertJsonPath('code', 'listing_not_relistable')->assertJsonPath('details.reason', 'not_taken_down');
});

it('is refused while everything is stopped', function () {
    sellerWithdraw($this, $this->seller, $this->listing)->assertOk();

    TopUps::actAsStaff($this, SeedRole::COO);
    $this->postJson('/api/v1/dashboard/category-controls', ['level' => 'stop_everything', 'reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    sellerRelist($this, $this->seller, $this->listing)->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN);
});

it('is refused while the seller is suspended', function () {
    sellerWithdraw($this, $this->seller, $this->listing)->assertOk();

    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'off_platform_dealing', 'suspended_at' => now(), 'suspended_by' => SystemActor::id(),
    ]);
    staffRelist($this, $this->listing)->assertStatus(409)->assertJsonPath('code', 'seller_suspended');
    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN);
});

it('counts and lists the withdrawn pieces for staff', function () {
    sellerWithdraw($this, $this->seller, $this->listing)->assertOk();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    $this->getJson(Listings::STAFF_URL.'?state=withdrawn')->assertOk()
        ->assertJsonPath('meta.counts.withdrawn', 1)
        ->assertJsonPath('data.0.id', $this->listing->listing_id)
        ->assertJsonPath('data.0.can_relist', true)
        ->assertJsonPath('data.0.taken_down_by', 'seller');
});
