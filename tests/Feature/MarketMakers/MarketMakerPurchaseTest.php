<?php

use App\Enums\SeedRole;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\MarketMakerApproval;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US6 / 6B: a market maker's own code at a buy request; every use is logged.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    // Roles and their permissions first: a staff member made earlier would otherwise stop the seeding.
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    $this->staff = Staff::factory()->create();
    $this->dealer = BuyRequests::funded('500000', ['customer_type' => 'market_maker']);
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create([
        'seller_id' => $this->seller->customer_id,
        'listed_at' => now()->subDays(30),
    ]);

    PromoCode::query()->create([
        'code' => 'DEALER-1', 'kind' => 'market_maker', 'tied_customer_id' => $this->dealer->customer_id,
        'commission_waived' => true, 'gives_spread' => true, 'created_by' => $this->staff->staff_id,
    ]);
    MarketMakerApproval::query()->create([
        'listing_id' => $this->listing->listing_id, 'approved_by' => $this->staff->staff_id, 'asking_price' => '1000',
    ]);
});

it('takes the request with the dealer\'s own code on an approved, aged piece and logs the use', function () {
    $res = BuyRequests::send($this, $this->dealer, $this->listing, ['promo_code' => 'DEALER-1'])->assertCreated();

    $request = BuyRequest::query()->findOrFail($res->json('data.id'));
    expect($request->promo_code)->toBe('DEALER-1');

    $use = PromoCodeUse::query()->where('code', 'DEALER-1')->sole();
    expect($use->allowed)->toBeTrue()->and($use->buy_request_id)->toBe($request->buy_request_id)->and($use->amount_egp)->not->toBeNull();
});

it('refuses another account\'s use of the code and logs it as blocked', function () {
    $other = BuyRequests::funded('500000');

    BuyRequests::send($this, $other, $this->listing, ['promo_code' => 'DEALER-1'])
        ->assertStatus(422)->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', 'not_your_code');

    $use = PromoCodeUse::query()->where('code', 'DEALER-1')->sole();
    expect($use->allowed)->toBeFalse()->and($use->block_reason)->toBe('not_your_code')->and($use->customer_id)->toBe($other->customer_id);
    expect(BuyRequest::query()->where('buyer_id', $other->customer_id)->count())->toBe(0);
});

it('refuses an unapproved piece, a turned-off code and a request over the monthly cap', function () {
    $plain = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id, 'listed_at' => now()->subDays(30)]);
    BuyRequests::send($this, $this->dealer, $plain, ['promo_code' => 'DEALER-1'])
        ->assertStatus(422)->assertJsonPath('details.reason', 'not_approved');

    PromoCode::query()->whereKey('DEALER-1')->update(['monthly_cap_egp' => '1']);
    BuyRequests::send($this, $this->dealer, $this->listing, ['promo_code' => 'DEALER-1'])
        ->assertStatus(422)->assertJsonPath('details.reason', 'cap_reached');

    PromoCode::query()->whereKey('DEALER-1')->update(['is_active' => false, 'monthly_cap_egp' => null,
        'deactivated_by' => $this->staff->staff_id, 'deactivated_at' => now()]);
    BuyRequests::send($this, $this->dealer, $this->listing, ['promo_code' => 'DEALER-1'])
        ->assertStatus(422)->assertJsonPath('details.reason', 'inactive');
});

it('is refused while everything is stopped', function () {
    TopUps::actAsStaff($this, SeedRole::COO);
    $this->postJson('/api/v1/dashboard/category-controls', ['level' => 'stop_everything', 'reason' => 'x'],
        ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    BuyRequests::send($this, $this->dealer, $this->listing, ['promo_code' => 'DEALER-1'])
        ->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
});

it('shows a market maker the market with approved pieces marked, and nobody else', function () {
    $other = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);

    $all = Listings::as($this, $this->dealer)->getJson('/api/v1/customer/me/market/listings')->assertOk()->json('data');
    $marks = collect($all)->pluck('mm_approved', 'id');
    expect($marks[$this->listing->listing_id])->toBeTrue()->and($marks[$other->listing_id])->toBeFalse();

    $only = Listings::as($this, $this->dealer)->getJson('/api/v1/customer/me/market/listings?mm_approved=1')->assertOk()->json('data');
    expect(collect($only)->pluck('id')->all())->toBe([$this->listing->listing_id]);

    Listings::as($this, $this->seller)->getJson('/api/v1/customer/me/market/listings')
        ->assertStatus(403)->assertJsonPath('code', 'market_maker_only');
});
