<?php

use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\ListingDecisionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US5; FR-030, FR-016: staff take a live listing down with a
// reason. Final; from live only.

const TAKEDOWN_REASON = 'The photos look taken from another listing.';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

function takeDown($test, Listing $listing, array $body, ?string $key = null)
{
    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/takedown", $body, Listings::key($key));
}

it('takes a live listing off the market with a reason', function () {
    takeDown($this, $this->listing, ['reason' => TAKEDOWN_REASON])->assertOk()
        ->assertJsonPath('data.state', 'withdrawn')
        ->assertJsonPath('data.can_take_down', false);

    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    $audit = AuditLog::query()->where('action', 'listing.taken_down')->where('entity_id', $this->listing->listing_id)->get();

    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and($change->from_state)->toBe('live')
        ->and($change->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($change->note)->toBe(TAKEDOWN_REASON)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->reason)->toBe(TAKEDOWN_REASON)
        ->and($audit[0]->before_json)->toBe(['state' => 'live'])
        ->and($audit[0]->actor_staff_id)->toBe($this->staff->staff_id);

    // Gone from the market at once; the seller sees why.
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertNotFound();
    expect(Listings::anonymous($this)->getJson(Listings::MARKET_URL)->json('data'))->toBe([]);

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.state', 'withdrawn')->assertJsonPath('data.staff_message', TAKEDOWN_REASON);

    Notification::assertSentTo($this->seller, ListingDecisionNotification::class,
        fn (ListingDecisionNotification $n) => $n->decision === ListingDecision::TAKEN_DOWN && $n->message === TAKEDOWN_REASON);
});

it('needs a reason', function (array $body) {
    takeDown($this, $this->listing, $body)->assertStatus(422)->assertJsonValidationErrors('reason');

    expect($this->listing->fresh()->state)->toBe(ListingState::LIVE);
    Notification::assertNothingSent();
})->with([[[]], [['reason' => 'short']], [['reason' => str_repeat('a', 1001)]], [['message' => TAKEDOWN_REASON]]]);

it('works from live only', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);

    takeDown($this, $listing, ['reason' => TAKEDOWN_REASON])->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    expect($listing->fresh()->state->value)->toBe(Str::snake($state))
        ->and(AuditLog::query()->where('action', 'listing.taken_down')->count())->toBe(0);
})->with(['draft', 'inReview', 'changesRequested', 'rejected', 'withdrawn', 'suspendedHold']);

it('is final', function () {
    takeDown($this, $this->listing, ['reason' => TAKEDOWN_REASON])->assertOk();

    takeDown($this, $this->listing, ['reason' => TAKEDOWN_REASON])->assertStatus(409);
    $this->postJson(Listings::STAFF_URL."/{$this->listing->listing_id}/approve", [], Listings::key())->assertStatus(409);
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/submit", [], Listings::key())->assertStatus(409);

    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN);
});

it('needs an idempotency key and acts once', function () {
    $this->postJson(Listings::STAFF_URL."/{$this->listing->listing_id}/takedown", ['reason' => TAKEDOWN_REASON])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    takeDown($this, $this->listing, ['reason' => TAKEDOWN_REASON], $key)->assertOk();
    takeDown($this, $this->listing, ['reason' => TAKEDOWN_REASON], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(AuditLog::query()->where('action', 'listing.taken_down')->count())->toBe(1);
    Notification::assertCount(1);
});
