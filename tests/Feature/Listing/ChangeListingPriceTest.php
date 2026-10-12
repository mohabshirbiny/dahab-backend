<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPriceChange;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Account;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 022 US1: the seller changes the figure of a live piece (contracts/listing-price-api.md §1).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

function changePrice($test, Customer $customer, Listing|string $listing, array $body, ?string $key = null)
{
    $id = $listing instanceof Listing ? $listing->listing_id : $listing;

    return Listings::as($test, $customer)->postJson(Listings::SELLER_URL."/{$id}/price", $body, Listings::key($key));
}

it('changes the making charge of a live gold piece, records it and keeps its place', function () {
    $before = $this->listing->fresh();
    $inboxBefore = DB::table('customer_notification')->count();
    $oldPrice = (string) Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$before->listing_id}")->json('data.current_price');

    $res = changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200.00'])->assertOk()
        ->assertJsonPath('data.state', 'live')
        ->assertJsonPath('data.making_charge_per_g', '200.0000')
        ->assertJsonPath('data.can_change_price', true);

    // 8 g at 50 EGP/g less than before, buyer's spread aside: the figure the buyer pays went down.
    expect(bccomp((string) $res->json('data.current_price'), $oldPrice, 4))->toBeLessThan(0)
        ->and($res->json('data.you_would_receive'))->not->toBeNull();

    $after = $this->listing->fresh();
    expect($after->state_changed_at->equalTo($before->state_changed_at))->toBeTrue()
        ->and($after->listed_at->equalTo($before->listed_at))->toBeTrue();

    $change = ListingPriceChange::query()->where('listing_id', $this->listing->listing_id)->sole();
    expect($change->field->value)->toBe('making_charge_per_g')
        ->and((string) $change->old_value)->toBe('250.0000')
        ->and((string) $change->new_value)->toBe('200.0000')
        ->and($change->actor_customer_id)->toBe($this->seller->customer_id)
        ->and($change->actor_staff_id)->toBeNull();

    // No state move: the history holds the creation, review and approval only.
    expect(DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->count())->toBe(3);

    $audit = AuditLog::query()->where('action', AuditEvent::LISTING_PRICE_CHANGED->value)->sole();
    expect($audit->actor_customer_id)->toBe($this->seller->customer_id)
        ->and($audit->entity_id)->toBe($this->listing->listing_id);

    // Nothing is sent to anyone (D22-2).
    Notification::assertNothingSent();
    expect(DB::table('customer_notification')->count())->toBe($inboxBefore);
});

it('shows the new price on the market and to a customer who saved the piece', function () {
    $saver = Customer::factory()->create();
    $saverToken = Listings::token($saver);
    Account::post($this, $saverToken, '/saved-pieces', ['listing_id' => $this->listing->listing_id])->assertCreated();

    $old = BuyRequests::price($this, $this->listing);
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'])->assertOk();

    $new = BuyRequests::price($this, $this->listing);
    expect(bccomp($new, $old, 4))->toBeLessThan(0);

    Account::as($this, $saverToken)->getJson(Account::ME.'/saved-pieces')->assertOk()
        ->assertJsonPath('data.0.listing.current_price', $new);
});

it('changes the asking price of a diamond and of a gold with diamond', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $mixed = Listing::factory()->goldWithDiamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);

    changePrice($this, $this->seller, $diamond, ['asking_price' => '110000.00'])->assertOk()
        ->assertJsonPath('data.asking_price', '110000.0000');
    changePrice($this, $this->seller, $mixed, ['asking_price' => '79000'])->assertOk()
        ->assertJsonPath('data.asking_price', '79000.0000');

    expect(ListingPriceChange::query()->where('listing_id', $diamond->listing_id)->sole()->field->value)->toBe('asking_price');
});

it('refuses the same figure, the other category\'s field and bad numbers', function () {
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '250'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.making_charge_per_g.0', 'This is already the price.');
    changePrice($this, $this->seller, $this->listing, ['asking_price' => '1000'])->assertStatus(422);
    changePrice($this, $this->seller, $this->listing, [])->assertStatus(422);
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200.005'])->assertStatus(422);
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '-1'])->assertStatus(422);

    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    changePrice($this, $this->seller, $diamond, ['asking_price' => '0'])->assertStatus(422);
    changePrice($this, $this->seller, $diamond, ['making_charge_per_g' => '10'])->assertStatus(422);

    expect(ListingPriceChange::query()->count())->toBe(0);
});

it('allows a making charge of zero', function () {
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '0'])->assertOk()
        ->assertJsonPath('data.making_charge_per_g', '0.0000');
});

it('refuses a piece somebody asked for, with has_requests, and leaves the figure', function () {
    BuyRequests::queued($this, BuyRequests::funded('60000'), $this->listing);

    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'])
        ->assertStatus(409)->assertJsonPath('code', 'listing_has_requests');

    expect((string) $this->listing->fresh()->making_charge_per_g)->toBe('250.0000')
        ->and(ListingPriceChange::query()->count())->toBe(0);

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")
        ->assertJsonPath('data.state', 'reserved')->assertJsonPath('data.can_change_price', false);
});

it('refuses every other state that is not live with listing_not_live', function () {
    $draft = Listing::factory()->withPhotos(2)->create(['seller_id' => $this->seller->customer_id]);
    $inReview = Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $this->seller->customer_id]);
    $changes = Listing::factory()->withPhotos(2)->changesRequested()->create(['seller_id' => $this->seller->customer_id]);
    $held = Listing::factory()->withPhotos(2)->suspendedHold()->create(['seller_id' => $this->seller->customer_id]);
    $withdrawn = Listing::factory()->withPhotos(2)->withdrawn()->create(['seller_id' => $this->seller->customer_id]);

    foreach ([$draft, $inReview, $changes, $held, $withdrawn] as $listing) {
        changePrice($this, $this->seller, $listing, ['making_charge_per_g' => '200'])
            ->assertStatus(409)->assertJsonPath('code', 'listing_not_live');
    }
});

it('refuses a piece approved for market makers without naming the programme', function () {
    $piece = Listing::factory()->withPhotos(2)->approvedForMarketMakers()->create(['seller_id' => $this->seller->customer_id]);

    $res = changePrice($this, $this->seller, $piece, ['making_charge_per_g' => '200'])
        ->assertStatus(409)->assertJsonPath('code', 'listing_price_locked');
    expect(strtolower((string) $res->json('message')))->not->toContain('market');

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$piece->listing_id}")
        ->assertJsonPath('data.can_change_price', false);
    expect((string) $piece->fresh()->making_charge_per_g)->toBe('250.0000');
});

describe('switches', function () {
    beforeEach(function () {
        $this->seed(DashboardRolesAndPermissionsSeeder::class);
    });

    function priceSwitch(string $level, ?string $category = null)
    {
        return test()->postJson('/api/v1/dashboard/category-controls',
            array_filter(['level' => $level, 'category' => $category, 'reason' => 'x']), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    it('is refused while everything is stopped', function () {
        TopUps::actAsStaff($this, SeedRole::COO);
        priceSwitch('stop_everything')->assertCreated();

        changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'])
            ->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
    });

    it('is allowed while new listings are stopped and while the category is paused', function () {
        TopUps::actAsStaff($this, SeedRole::COO);
        priceSwitch('stop_new_listings', 'gold')->assertCreated();
        changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'])->assertOk();

        TopUps::actAsStaff($this, SeedRole::COO);
        priceSwitch('pause_category', 'gold')->assertCreated();
        changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '190'])->assertOk();
    });
});

it('is refused to a suspended seller, an unverified customer and another seller, and needs the key', function () {
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $pending = Customer::factory()->pendingVerification()->create();
    $other = Customer::factory()->verified()->create();

    changePrice($this, $suspended, $this->listing, ['making_charge_per_g' => '200'])->assertForbidden()->assertJsonPath('code', 'account_suspended');
    changePrice($this, $pending, $this->listing, ['making_charge_per_g' => '200'])->assertForbidden()->assertJsonPath('code', 'verification_required');
    changePrice($this, $other, $this->listing, ['making_charge_per_g' => '200'])->assertNotFound();

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/price", ['making_charge_per_g' => '200'])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('replays the same key without a second change', function () {
    $key = (string) Str::uuid();

    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'], $key)->assertOk();
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '200'], $key)->assertOk();

    expect(ListingPriceChange::query()->count())->toBe(1);
});

it('keeps PATCH refusing a live piece', function () {
    Listings::as($this, $this->seller)->patchJson(Listings::SELLER_URL."/{$this->listing->listing_id}", ['making_charge_per_g' => '200'], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'listing_not_editable');
});

it('keeps a price change inside the spec 011 tolerance honest: a buyer who confirmed the old price is refused', function () {
    $old = BuyRequests::price($this, $this->listing);
    changePrice($this, $this->seller, $this->listing, ['making_charge_per_g' => '120'])->assertOk();

    BuyRequests::send($this, BuyRequests::funded('60000'), $this->listing, ['confirm_locked_price' => $old])
        ->assertStatus(409)->assertJsonPath('code', 'price_moved');
});
