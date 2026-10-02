<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US4 scenarios 1, 5, 6; FR-018: the seller's own listings.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

function ownListing(Customer $seller, string $state = 'draft', ...$args): Listing
{
    return Listing::factory()->withPhotos(2)->{$state}(...$args)->create(['seller_id' => $seller->customer_id]);
}

it('lists only the seller\'s own listings, newest first', function () {
    $old = ownListing($this->seller);
    $this->travel(1)->minutes();
    $new = ownListing($this->seller, 'live');
    ownListing(Customer::factory()->verified()->create(), 'live');

    $res = Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL)->assertOk()
        ->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.next_cursor', null);

    expect(array_column($res->json('data'), 'id'))->toBe([$new->listing_id, $old->listing_id])
        ->and(array_column($res->json('data'), 'state'))->toBe(['live', 'draft']);
});

it('filters by state and pages by cursor', function () {
    foreach (range(1, 3) as $i) {
        ownListing($this->seller, 'live');
        $this->travel(1)->seconds();
    }
    ownListing($this->seller);

    $first = Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?state=live&per_page=2')->assertOk();
    $second = Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?state=live&per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.next_cursor'))->toBeString()
        ->and($second->json('data'))->toHaveCount(1)
        ->and($second->json('meta.next_cursor'))->toBeNull()
        ->and(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toBe([]);

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?cursor=garbage')->assertStatus(422)->assertJsonValidationErrors('cursor');
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?state=nonsense')->assertStatus(422)->assertJsonValidationErrors('state');
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?per_page=51')->assertStatus(422);
});

it('shows one listing with everything the seller attached', function () {
    $listing = Listing::factory()->withPhotos(2)->withInvoice()->live()->create(['seller_id' => $this->seller->customer_id]);

    $data = Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$listing->listing_id}")->assertOk()->json('data');

    expect(array_keys($data))->toBe([
        'id', 'state', 'category', 'piece_type', 'karat', 'stated_weight_g', 'making_charge_per_g', 'asking_price', 'description',
        'media', 'branch_options', 'current_price', 'price_available', 'price_is_indicative', 'you_would_receive',
        'staff_message', 'staff_message_at', 'created_at', 'listed_at', 'state_changed_at', 'can_edit', 'can_submit', 'can_withdraw',
        'queue_count', 'order', // spec 011
    ])
        ->and(array_column($data['media'], 'kind'))->toBe(['photo', 'photo', 'invoice'])
        ->and(collect($data['media'])->firstWhere('kind', 'invoice')['is_private'])->toBeTrue()
        ->and($data['can_withdraw'])->toBeTrue()
        ->and($data['can_edit'])->toBeFalse()
        ->and($data['listed_at'])->not->toBeNull();
});

it('shows what a buyer would pay and what the seller would receive', function () {
    $listing = ownListing($this->seller, 'live');
    $context = app(PricingContext::class);
    $expected = $context->breakdown(Piece::gold($context->pricing(21), '8.000', '250.00'));

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$listing->listing_id}")->assertOk()
        ->assertJsonPath('data.current_price', $expected->buyerTotal)
        ->assertJsonPath('data.you_would_receive', $expected->sellerProceeds)
        ->assertJsonPath('data.price_available', true)
        ->assertJsonPath('data.price_is_indicative', true);

    expect(bccomp($expected->buyerTotal, $expected->sellerProceeds, 4))->toBe(1);
});

it('shows the staff message while it applies', function (string $state, ?string $message) {
    $listing = $message === null ? ownListing($this->seller, $state) : ownListing($this->seller, $state, $message);

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$listing->listing_id}")->assertOk()
        ->assertJsonPath('data.staff_message', $message);
})->with([
    'changes requested' => ['changesRequested', 'The hallmark photo is blurred.'],
    'rejected' => ['rejected', 'The photos are not of this piece.'],
    'a draft' => ['draft', null],
    'live' => ['live', null],
    'withdrawn by the seller' => ['withdrawn', null],
    'on hold (the note is internal)' => ['suspendedHold', null],
]);

it('says what the seller may do in each state', function (string $state, bool $edit, bool $withdraw) {
    $listing = ownListing($this->seller, $state);

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$listing->listing_id}")->assertOk()
        ->assertJsonPath('data.can_edit', $edit)->assertJsonPath('data.can_submit', $edit)->assertJsonPath('data.can_withdraw', $withdraw);
})->with([
    ['draft', true, false], ['inReview', false, false], ['changesRequested', true, false],
    ['live', false, true], ['rejected', false, false], ['withdrawn', false, false], ['suspendedHold', false, false],
]);

it('answers not found for another seller\'s listing', function () {
    $theirs = ownListing(Customer::factory()->verified()->create(), 'live');

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$theirs->listing_id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'/'.Str::uuid())->assertNotFound();
});

it('lets a suspended seller read, and refuses an unverified customer', function () {
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $pending = Customer::factory()->pendingVerification()->create();
    $listing = ownListing($suspended);

    Listings::as($this, $suspended)->getJson(Listings::SELLER_URL)->assertOk()->assertJsonCount(1, 'data');
    Listings::as($this, $suspended)->getJson(Listings::SELLER_URL."/{$listing->listing_id}")->assertOk();
    Listings::as($this, $pending)->getJson(Listings::SELLER_URL)->assertForbidden()->assertJsonPath('code', 'verification_required');
    Listings::anonymous($this)->getJson(Listings::SELLER_URL)->assertUnauthorized();
});
