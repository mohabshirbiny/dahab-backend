<?php

use App\Enums\ListingState;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US4 scenario 4; FR-014, FR-016: the seller takes a live listing
// off the market. Final; from live only.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

function withdrawListing($test, Customer $customer, Listing $listing, ?string $key = null)
{
    return Listings::as($test, $customer)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/withdraw", [], Listings::key($key));
}

it('takes a live listing off the market at once', function () {
    $photo = $this->listing->media()->first();
    $url = Listings::MARKET_URL."/{$this->listing->listing_id}";

    Listings::anonymous($this)->getJson($url)->assertOk();
    Listings::anonymous($this)->get("{$url}/media/{$photo->media_id}")->assertOk();

    withdrawListing($this, $this->seller, $this->listing)->assertOk()
        ->assertJsonPath('data.state', 'withdrawn')
        ->assertJsonPath('data.can_withdraw', false)
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.staff_message', null);

    $last = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();

    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and($this->listing->fresh()->listed_at)->not->toBeNull()
        ->and($last->to_state)->toBe('withdrawn')
        ->and($last->actor_customer_id)->toBe($this->seller->customer_id)
        ->and(DB::table('audit_log')->where('entity_type', 'listing')->count())->toBe(0);

    Listings::anonymous($this)->getJson($url)->assertNotFound();
    Listings::anonymous($this)->getJson("{$url}/media/{$photo->media_id}")->assertNotFound();
    expect(collect(Listings::anonymous($this)->getJson(Listings::MARKET_URL)->json('data'))->pluck('id')->all())->toBe([]);
});

it('is final: a withdrawn listing cannot be edited, resubmitted or withdrawn again', function () {
    withdrawListing($this, $this->seller, $this->listing)->assertOk();
    $id = $this->listing->listing_id;

    Listings::as($this, $this->seller)->patchJson(Listings::SELLER_URL."/{$id}", ['description' => str_repeat('a', 50)], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'listing_not_editable');
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$id}/submit", [], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
    withdrawListing($this, $this->seller, $this->listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN);
});

it('works from live only', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);

    withdrawListing($this, $this->seller, $listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    expect($listing->fresh()->state->value)->toBe(Str::snake($state));
})->with(['draft', 'inReview', 'changesRequested', 'rejected', 'suspendedHold']);

it('hides another seller\'s listing and refuses customers who may not trade', function () {
    $other = Customer::factory()->verified()->create();

    withdrawListing($this, $other, $this->listing)->assertNotFound();
    Listings::anonymous($this)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/withdraw", [], Listings::key())->assertUnauthorized();

    // A seller suspended after going live cannot withdraw; their listing is on hold anyway.
    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);
    withdrawListing($this, $this->seller, $this->listing)->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect($this->listing->fresh()->state)->toBe(ListingState::LIVE);
});

it('needs an idempotency key and acts once', function () {
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/withdraw")
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    withdrawListing($this, $this->seller, $this->listing, $key)->assertOk();
    withdrawListing($this, $this->seller, $this->listing, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->where('to_state', 'withdrawn')->count())->toBe(1);
});
