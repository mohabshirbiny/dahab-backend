<?php

use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use Database\Factories\ListingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US2 scenario 1; FR-027; research R14/R15: the staff review queue
// and the review panel's figures.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->seller = Customer::factory()->verified()->create(['display_ref' => '008842', 'full_name' => 'Sara M. Abdelrahman', 'phone' => '+201012348842']);
});

function waiting(Customer $seller, string $state = 'inReview'): Listing
{
    return Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $seller->customer_id]);
}

it('lists waiting listings of every seller, oldest first', function () {
    $first = waiting($this->seller);
    $this->travel(1)->minutes();
    $second = waiting(Customer::factory()->verified()->create());
    $this->travel(1)->minutes();
    $third = waiting($this->seller);
    waiting($this->seller, 'draft');
    waiting($this->seller, 'live');

    $res = $this->getJson(Listings::STAFF_URL)->assertOk()->assertJsonPath('meta.per_page', 25);

    expect(array_column($res->json('data'), 'id'))->toBe([$first->listing_id, $second->listing_id, $third->listing_id])
        ->and(array_unique(array_column($res->json('data'), 'state')))->toBe(['in_review']);
});

it('lists the other states most recent first', function () {
    $old = waiting($this->seller, 'live');
    $this->travel(1)->minutes();
    $new = waiting($this->seller, 'live');

    $ids = array_column($this->getJson(Listings::STAFF_URL.'?state=live')->assertOk()->json('data'), 'id');

    expect($ids)->toBe([$new->listing_id, $old->listing_id]);
});

it('pages by cursor without repeating', function () {
    foreach (range(1, 5) as $i) {
        waiting($this->seller);
        $this->travel(1)->seconds();
    }

    $first = $this->getJson(Listings::STAFF_URL.'?per_page=2')->assertOk();
    $second = $this->getJson(Listings::STAFF_URL.'?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
    $third = $this->getJson(Listings::STAFF_URL.'?per_page=2&cursor='.$second->json('meta.next_cursor'))->assertOk();

    $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id'));

    expect($ids)->toHaveCount(5)->and(array_unique($ids))->toHaveCount(5)
        ->and($third->json('meta.next_cursor'))->toBeNull();

    $this->getJson(Listings::STAFF_URL.'?cursor=nonsense')->assertStatus(422)->assertJsonValidationErrors('cursor');
    $this->getJson(Listings::STAFF_URL.'?state=nonsense')->assertStatus(422);
});

it('counts the listings for the chips', function () {
    waiting($this->seller);
    waiting($this->seller);
    waiting($this->seller, 'changesRequested');
    waiting($this->seller, 'rejected');
    waiting($this->seller, 'live');

    // An approval from yesterday does not count as "approved today".
    $yesterday = waiting($this->seller, 'live');
    DB::statement('ALTER TABLE listing_state_change DISABLE TRIGGER trg_listing_state_change_immutable');
    DB::table('listing_state_change')->where('listing_id', $yesterday->listing_id)->where('to_state', 'live')->update(['changed_at' => now()->subDay()]);
    DB::statement('ALTER TABLE listing_state_change ENABLE TRIGGER trg_listing_state_change_immutable');

    $this->getJson(Listings::STAFF_URL)->assertOk()
        ->assertJsonPath('meta.counts', ['in_review' => 2, 'changes_requested' => 1, 'approved_today' => 1, 'rejected' => 1, 'live' => 2]);
});

it('shows the piece, its price and the seller on each row', function () {
    $listing = waiting($this->seller);

    $row = $this->getJson(Listings::STAFF_URL)->assertOk()->json('data.0');

    expect($row['id'])->toBe($listing->listing_id)
        ->and($row['seller'])->toBe([
            'id' => $this->seller->customer_id, 'display_ref' => '008842', 'full_name' => 'Sara M. Abdelrahman',
            'phone_masked' => '+20 10 •••• 8842', 'status' => 'active',
        ])
        ->and($row['current_price'])->not->toBeNull()
        ->and($row['gold_rate_per_gram'])->not->toBeNull()
        ->and($row['state_changed_at'])->not->toBeNull()
        ->and($row['can_approve'])->toBeTrue()
        ->and($row['can_request_changes'])->toBeTrue()
        ->and($row['can_reject'])->toBeTrue()
        ->and($row['can_take_down'])->toBeFalse()
        ->and($row)->not->toHaveKey('history')
        ->and(json_encode($row))->not->toContain('+201012348842')->not->toContain('listing-media/');
});

it('shows one listing with its media, history and counters', function () {
    // The seller's first listing was sent back twice; their second once; a third never.
    $listing = Listing::factory()->withPhotos(2)->withInvoice()->changesRequested('First note.')->create(['seller_id' => $this->seller->customer_id]);
    ListingFactory::walk($listing, [ListingState::IN_REVIEW, ListingState::CHANGES_REQUESTED], 'Second note.', $this->staff->staff_id);
    ListingFactory::walk($listing, [ListingState::IN_REVIEW]);
    $this->travel(1)->minutes();
    waiting($this->seller, 'changesRequested');
    $this->travel(1)->minutes();
    $third = waiting($this->seller);
    waiting($this->seller, 'draft');

    $data = $this->getJson(Listings::STAFF_URL."/{$listing->listing_id}")->assertOk()->json('data');

    expect(array_column($data['media'], 'kind'))->toBe(['photo', 'photo', 'invoice'])
        ->and($data['media'][2]['is_private'])->toBeTrue()
        ->and($data['media'][0]['url'])->toBe(Listings::STAFF_URL."/{$listing->listing_id}/media/".$data['media'][0]['id'])
        ->and($data['sent_back_count'])->toBe(2)
        ->and($data['seller_listings_sent_back'])->toBe(2)
        ->and($data['seller_listings_submitted'])->toBe(3)
        ->and($data['seller_listing_number'])->toBe(1)
        ->and(array_column($data['history'], 'to_state'))->toBe(['draft', 'in_review', 'changes_requested', 'in_review', 'changes_requested', 'in_review'])
        ->and($data['history'][0]['from_state'])->toBeNull()
        ->and($data['history'][0]['actor'])->toBe(['type' => 'customer', 'name' => 'Sara M. Abdelrahman'])
        ->and($data['history'][4]['actor'])->toBe(['type' => 'staff', 'name' => $this->staff->full_name])
        ->and($data['history'][4]['note'])->toBe('Second note.');

    $this->getJson(Listings::STAFF_URL."/{$third->listing_id}")->assertOk()
        ->assertJsonPath('data.seller_listing_number', 3)->assertJsonPath('data.sent_back_count', 0);

    $this->getJson(Listings::STAFF_URL.'/'.Str::uuid())->assertNotFound();
});

it('says what this staff member may do, by state and permission', function (string $state, array $can) {
    $listing = waiting($this->seller, $state);

    $data = $this->getJson(Listings::STAFF_URL."/{$listing->listing_id}")->assertOk()->json('data');

    expect([$data['can_approve'], $data['can_request_changes'], $data['can_reject'], $data['can_take_down']])->toBe($can);
})->with([
    'in review' => ['inReview', [true, true, true, false]],
    'live' => ['live', [false, false, false, true]],
    'changes requested' => ['changesRequested', [false, false, false, false]],
    'rejected' => ['rejected', [false, false, false, false]],
    'withdrawn' => ['withdrawn', [false, false, false, false]],
]);

it('loads a page in a bounded number of queries', function () {
    foreach (range(1, 8) as $i) {
        waiting(Customer::factory()->verified()->create());
    }

    DB::enableQueryLog();
    $this->getJson(Listings::STAFF_URL)->assertOk()->assertJsonCount(8, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The count must not grow with the number of rows (no N+1).
    expect($queries)->toBeLessThan(40);
});
