<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\MarketMakerApproval;
use App\Models\PromoCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US6 / 6A: market-maker codes, the approval queue, the cost panel.

function mmKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

function mmDealer(): Customer
{
    return Customer::factory()->verified()->create(['customer_type' => 'market_maker']);
}

it('creates a market-maker code tied to one dealer, and refuses every other kind or account', function () {
    Listings::goldPrice();
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $dealer = mmDealer();

    $this->postJson('/api/v1/dashboard/promo-codes', ['code' => 'ahmed-gold', 'tied_customer_id' => $dealer->customer_id, 'monthly_cap_egp' => 500000], mmKey())
        ->assertCreated()->assertJsonPath('data.code', 'AHMED-GOLD');

    // Spec 023: a first-sale code is built now, but not for a dealer's account (the kind contradicts the tie).
    $this->postJson('/api/v1/dashboard/promo-codes', ['kind' => 'first_sale', 'code' => 'WELCOME', 'tied_customer_id' => $dealer->customer_id], mmKey())
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $ordinary = Customer::factory()->verified()->create();
    $this->postJson('/api/v1/dashboard/promo-codes', ['code' => 'NOTDEALER', 'tied_customer_id' => $ordinary->customer_id], mmKey())
        ->assertStatus(422)->assertJsonPath('code', 'promo_customer_not_market_maker');

    // One code per dealer.
    $this->postJson('/api/v1/dashboard/promo-codes', ['code' => 'SECOND', 'tied_customer_id' => $dealer->customer_id], mmKey())
        ->assertStatus(409)->assertJsonPath('code', 'promo_code_exists');

    $this->patchJson('/api/v1/dashboard/promo-codes/AHMED-GOLD', ['active' => false, 'monthly_cap_egp' => 250000], mmKey())
        ->assertOk()->assertJsonPath('data.is_active', false);
    expect(PromoCode::query()->findOrFail('AHMED-GOLD')->deactivated_by)->not->toBeNull();

    $this->getJson('/api/v1/dashboard/promo-codes/AHMED-GOLD/uses')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/dashboard/market-maker/cost')->assertOk()->assertJsonPath('data.pieces_bought', 0);
});

it('is for Finance, not for Operations', function () {
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    $this->getJson('/api/v1/dashboard/promo-codes')->assertForbidden();
    $this->getJson('/api/v1/dashboard/market-maker/queue')->assertForbidden();
});

it('queues aged live pieces, approves each once with the numbers seen, and refuses a new piece', function () {
    Listings::goldPrice();
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $seller = Customer::factory()->verified()->create();
    $old = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id, 'listed_at' => now()->subDays(30)]);
    $new = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id, 'listed_at' => now()->subDay()]);

    $ids = collect($this->getJson('/api/v1/dashboard/market-maker/queue')->assertOk()->json('data'))->pluck('listing_id');
    expect($ids)->toContain($old->listing_id)->not->toContain($new->listing_id);

    $this->postJson('/api/v1/dashboard/market-maker/approvals', ['listing_id' => $new->listing_id, 'asking_price_seen' => 1000], mmKey())
        ->assertStatus(422)->assertJsonPath('code', 'listing_too_new');

    $this->postJson('/api/v1/dashboard/market-maker/approvals', ['listing_id' => $old->listing_id, 'asking_price_seen' => 1000, 'gold_value_seen' => 900], mmKey())
        ->assertCreated();
    $this->postJson('/api/v1/dashboard/market-maker/approvals', ['listing_id' => $old->listing_id, 'asking_price_seen' => 1000], mmKey())
        ->assertStatus(409)->assertJsonPath('code', 'already_approved');

    expect(MarketMakerApproval::query()->where('listing_id', $old->listing_id)->value('gold_value'))->toBe('900.0000');

    $state = null;
    try {
        DB::transaction(fn () => DB::table('market_maker_approval')->where('listing_id', $old->listing_id)->delete());
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('DH020');
});
