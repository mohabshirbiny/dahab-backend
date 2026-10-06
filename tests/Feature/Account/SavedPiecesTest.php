<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SavedListing;
use App\Support\DatabaseActor;
use App\Support\Pricing\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Account;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 017 US5, FR-040: saved pieces — any signed-in customer, pieces on the
// market, the indicative price, "no longer available" once a piece leaves.

beforeEach(function () {
    Orders::workedPrices();
    $this->piece = Orders::ring();
    $this->customer = Customer::factory()->create();
    $this->token = Listings::token($this->customer);
});

it('saves a piece once and lists it with the market price', function () {
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $this->piece->listing_id])->assertCreated()
        ->assertJsonPath('data.available', true)->assertJsonPath('data.listing.id', $this->piece->listing_id)
        ->assertJsonPath('data.summary.karat', 21);
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $this->piece->listing_id])->assertCreated();

    $res = Account::as($this, $this->token)->getJson(Account::ME.'/saved-pieces')->assertOk()->assertJsonCount(1, 'data');
    expect($res->json('data.0.listing.current_price'))->not->toBeNull()
        ->and($res->json('data.0.listing.is_mine'))->toBeFalse()
        ->and(DatabaseActor::elevate('system', fn () => SavedListing::query()->count()))->toBe(1);
    Account::as($this, $this->token)->getJson(Account::ME.'/saved-pieces?listing_id='.$this->piece->listing_id)->assertJsonCount(1, 'data');
});

it('shows a piece that left the market without price or photos, and removes it', function () {
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $this->piece->listing_id])->assertCreated();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(Listings::STAFF_URL."/{$this->piece->listing_id}/takedown", ['reason' => 'Photos taken from elsewhere'], Listings::key())->assertOk();

    Account::as($this, $this->token)->getJson(Account::ME.'/saved-pieces')->assertOk()
        ->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.listing', null)
        ->assertJsonPath('data.0.summary.weight_g', '10.000');

    Account::as($this, $this->token)->deleteJson(Account::ME.'/saved-pieces/'.$this->piece->listing_id)->assertNoContent();
    Account::as($this, $this->token)->deleteJson(Account::ME.'/saved-pieces/'.$this->piece->listing_id)->assertNoContent();
    Account::as($this, $this->token)->getJson(Account::ME.'/saved-pieces')->assertJsonCount(0, 'data');
});

it('refuses a piece that is not on the market', function () {
    $draft = Listing::factory()->create(['seller_id' => Customer::factory()->verified()->create()->customer_id]);

    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $draft->listing_id])
        ->assertUnprocessable()->assertJsonPath('code', 'listing_not_saveable');
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('code', 'listing_not_saveable');
});

it('stops at the saved.max_per_customer setting', function () {
    DB::table('setting')->where('setting_key', 'saved.max_per_customer')->update(['value_numeric' => 1]);
    app()->forgetInstance(Settings::class);
    $other = Orders::ring();

    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $this->piece->listing_id])->assertCreated();
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $other->listing_id])
        ->assertUnprocessable()->assertJsonPath('code', 'saved_limit_reached')->assertJsonPath('details.limit', 1);
});

it('keeps each customer to their own saved pieces', function () {
    Account::post($this, $this->token, '/saved-pieces', ['listing_id' => $this->piece->listing_id])->assertCreated();
    $other = Listings::token(Customer::factory()->create());

    Account::as($this, $other)->getJson(Account::ME.'/saved-pieces')->assertOk()->assertJsonCount(0, 'data');
    Account::as($this, $other)->deleteJson(Account::ME.'/saved-pieces/'.$this->piece->listing_id)->assertNoContent();
    expect(DatabaseActor::elevate('system', fn () => SavedListing::query()->count()))->toBe(1);
});
