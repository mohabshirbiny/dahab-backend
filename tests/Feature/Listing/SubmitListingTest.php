<?php

use App\Enums\ListingState;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Karat;
use App\Models\Listing;
use App\Models\PieceType;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US1 scenario 5, US4 scenario 3; FR-013; contract POST
// /customer/me/listings/{listing}/submit.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seller = Customer::factory()->verified()->create();
});

function submitListing($test, Customer $customer, Listing|string $listing, ?string $key = null)
{
    $id = $listing instanceof Listing ? $listing->listing_id : $listing;

    return Listings::as($test, $customer)->postJson(Listings::SELLER_URL."/{$id}/submit", [], Listings::key($key));
}

function draftOf(Customer $seller, int $photos = 2, array $attributes = [], string $category = 'gold'): Listing
{
    $factory = Listing::factory()->withPhotos($photos);
    $factory = match ($category) {
        'diamond' => $factory->diamond(),
        'gold_with_diamond' => $factory->goldWithDiamond(),
        default => $factory,
    };

    return $factory->create(['seller_id' => $seller->customer_id] + $attributes);
}

it('sends a draft for review, in the seller\'s name', function () {
    $listing = draftOf($this->seller);

    submitListing($this, $this->seller, $listing)->assertOk()
        ->assertJsonPath('data.state', 'in_review')
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.can_submit', false)
        ->assertJsonPath('data.listed_at', null);

    $last = DB::table('listing_state_change')->where('listing_id', $listing->listing_id)->orderByDesc('change_id')->first();

    expect($listing->fresh()->state)->toBe(ListingState::IN_REVIEW)
        ->and($last->from_state)->toBe('draft')
        ->and($last->to_state)->toBe('in_review')
        ->and($last->actor_customer_id)->toBe($this->seller->customer_id)
        ->and($last->actor_staff_id)->toBeNull()
        ->and(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toBeTrue();
});

it('resubmits a listing that was sent back', function () {
    $listing = Listing::factory()->withPhotos(2)->changesRequested()->create(['seller_id' => $this->seller->customer_id]);

    submitListing($this, $this->seller, $listing)->assertOk()
        ->assertJsonPath('data.state', 'in_review')
        ->assertJsonPath('data.staff_message', null);
});

it('needs enough photos for the category', function (string $category, int $photos, int $minimum) {
    $listing = draftOf($this->seller, $photos, category: $category);

    submitListing($this, $this->seller, $listing)->assertStatus(422)
        ->assertJsonPath('code', 'photo_required')
        ->assertJsonPath('message', "Add at least {$minimum} photos before sending the piece for review.");

    expect($listing->fresh()->state)->toBe(ListingState::DRAFT);
})->with([
    'gold with one photo' => ['gold', 1, 2],
    'gold with none' => ['gold', 0, 2],
    'a diamond with two' => ['diamond', 2, 3],
    'gold with diamond with two' => ['gold_with_diamond', 2, 3],
]);

it('accepts a diamond with three photos', function () {
    submitListing($this, $this->seller, draftOf($this->seller, 3, category: 'diamond'))->assertOk()->assertJsonPath('data.state', 'in_review');
});

it('does not count the video or the documents as photos', function () {
    $listing = Listing::factory()->withPhotos(1)->withInvoice()->withCertificate()->create(['seller_id' => $this->seller->customer_id]);

    submitListing($this, $this->seller, $listing)->assertStatus(422)->assertJsonPath('code', 'photo_required');
});

it('needs a description of 40 to 2000 characters', function (?string $description) {
    $listing = draftOf($this->seller, 2, ['description' => $description]);

    submitListing($this, $this->seller, $listing)->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('description');

    expect($listing->fresh()->state)->toBe(ListingState::DRAFT);
})->with([
    'none' => [null],
    '39 characters' => [str_repeat('a', 39)],
    'spaces only' => [str_repeat(' ', 60)],
]);

it('accepts a description of exactly 40 characters', function () {
    submitListing($this, $this->seller, draftOf($this->seller, 2, ['description' => str_repeat('a', 40)]))->assertOk();
});

it('refuses a karat or a piece type turned off since the draft', function (Closure $disable, string $field) {
    $listing = draftOf($this->seller);
    $disable($listing);

    submitListing($this, $this->seller, $listing)->assertStatus(422)->assertJsonValidationErrors($field);

    expect($listing->fresh()->state)->toBe(ListingState::DRAFT);
})->with([
    'the karat' => [fn (Listing $l) => Karat::query()->whereKey($l->karat_code)->update(['is_enabled' => false]), 'karat_code'],
    'the piece type' => [fn (Listing $l) => PieceType::query()->whereKey($l->piece_type_id)->update(['is_enabled' => false]), 'piece_type_id'],
]);

it('needs at least one named branch that is still open', function () {
    $listing = draftOf($this->seller);
    $open = Branch::factory()->create();
    DB::table('listing_branch_option')->insert(['listing_id' => $listing->listing_id, 'branch_id' => $open->branch_id]);
    $first = $listing->branches()->where('branch.branch_id', '!=', $open->branch_id)->first();

    Branch::query()->whereKey($first->branch_id)->update(['is_enabled' => false]);
    submitListing($this, $this->seller, $listing)->assertOk();

    $second = draftOf($this->seller);
    Branch::query()->whereIn('branch_id', $second->branches()->pluck('branch.branch_id'))->update(['is_enabled' => false]);
    submitListing($this, $this->seller, $second)->assertStatus(422)->assertJsonPath('code', 'branch_options_required');
});

it('refuses a listing that is not a draft or sent back', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    $before = DB::table('listing_state_change')->count();

    submitListing($this, $this->seller, $listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    expect(DB::table('listing_state_change')->count())->toBe($before);
})->with(['inReview', 'live', 'withdrawn', 'rejected', 'suspendedHold']);

it('says illegal transition before anything else for a closed listing', function () {
    // No photos and no description, but it is rejected: the state decides first.
    $listing = Listing::factory()->withPhotos(2)->rejected()->create(['seller_id' => $this->seller->customer_id]);
    DB::table('listing_media')->where('listing_id', $listing->listing_id)->delete();

    submitListing($this, $this->seller, $listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
});

it('hides another seller\'s listing', function () {
    $other = Customer::factory()->verified()->create();
    $listing = draftOf($this->seller);

    submitListing($this, $other, $listing)->assertNotFound()->assertJsonPath('code', 'not_found');
    submitListing($this, $this->seller, (string) Str::uuid())->assertNotFound();

    expect($listing->fresh()->state)->toBe(ListingState::DRAFT);
});

it('refuses customers who may not trade', function () {
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $listing = draftOf($suspended);

    submitListing($this, $suspended, $listing)->assertForbidden()->assertJsonPath('code', 'account_suspended');
    Listings::anonymous($this)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/submit", [], Listings::key())->assertUnauthorized();
});

it('needs an idempotency key and acts once', function () {
    $listing = draftOf($this->seller);

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/submit")
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    submitListing($this, $this->seller, $listing, $key)->assertOk();
    submitListing($this, $this->seller, $listing, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.state', 'in_review');

    expect(DB::table('listing_state_change')->where('listing_id', $listing->listing_id)->where('to_state', 'in_review')->count())->toBe(1);

    // A new key is a new attempt: the listing is already in review.
    submitListing($this, $this->seller, $listing)->assertStatus(409);
});
