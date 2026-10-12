<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPriceChange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 022 R4, R7, R9: a buyer and a price change never both win; the staff detail shows the history;
// the seller's list says which pieces may change.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create(['full_name' => 'Mona Adel']);
    $this->live = fn () => Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

it('keeps the seller\'s figure for a buyer who is already in line, and refuses a later change', function () {
    $listing = ($this->live)();
    $buyer = BuyRequests::funded('60000');
    $request = BuyRequests::queued($this, $buyer, $listing);
    $locked = (string) $request->locked_total_price;

    Listings::as($this, $this->seller)
        ->postJson(Listings::SELLER_URL."/{$listing->listing_id}/price", ['making_charge_per_g' => '10'], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'listing_has_requests');

    expect((string) $listing->fresh()->making_charge_per_g)->toBe('250.0000')
        ->and((string) $request->fresh()->locked_total_price)->toBe($locked)
        ->and(ListingPriceChange::query()->count())->toBe(0);

    // The database says the same, whatever the application does.
    $state = null;
    try {
        DB::transaction(fn () => DB::table('listing')->where('listing_id', $listing->listing_id)->update(['making_charge_per_g' => '10']));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('DH023');
});

it('says per piece whether the price can change, in one list', function () {
    $free = ($this->live)();
    $asked = ($this->live)();
    BuyRequests::queued($this, BuyRequests::funded('60000'), $asked);
    $approved = Listing::factory()->withPhotos(2)->approvedForMarketMakers()->create(['seller_id' => $this->seller->customer_id]);
    $draft = Listing::factory()->withPhotos(2)->create(['seller_id' => $this->seller->customer_id]);

    $rows = collect(Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL.'?per_page=50')->assertOk()->json('data'))
        ->keyBy('id');

    expect($rows[$free->listing_id]['can_change_price'])->toBeTrue()
        ->and($rows[$asked->listing_id]['can_change_price'])->toBeFalse()
        ->and($rows[$approved->listing_id]['can_change_price'])->toBeFalse()
        ->and($rows[$draft->listing_id]['can_change_price'])->toBeFalse();
});

it('lists the price changes on the staff detail, oldest first, and not on the list', function () {
    $listing = ($this->live)();
    $key = fn () => Listings::key();
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/price", ['making_charge_per_g' => '230'], $key())->assertOk();
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/price", ['making_charge_per_g' => '215.50'], $key())->assertOk();

    foreach ([SeedRole::OPERATIONS, SeedRole::CEO, SeedRole::COO] as $role) {
        Listings::actAsStaff($this, $role);
        $history = $this->getJson(Listings::STAFF_URL."/{$listing->listing_id}")->assertOk()->json('data.price_history');

        expect($history)->toHaveCount(2)
            ->and($history[0]['field'])->toBe('making_charge_per_g')
            ->and($history[0]['old_value'])->toBe('250.0000')
            ->and($history[0]['new_value'])->toBe('230.0000')
            ->and($history[1]['new_value'])->toBe('215.5000')
            ->and($history[0]['actor'])->toBe(['type' => 'customer', 'name' => 'Mona Adel'])
            ->and($history[0]['changed_at'])->not->toBeNull();
    }

    $none = ($this->live)();
    $this->getJson(Listings::STAFF_URL."/{$none->listing_id}")->assertOk()->assertJsonPath('data.price_history', []);

    $row = collect($this->getJson(Listings::STAFF_URL.'?state=live')->assertOk()->json('data'))->firstWhere('id', $listing->listing_id);
    expect($row)->not->toHaveKey('price_history');
});
