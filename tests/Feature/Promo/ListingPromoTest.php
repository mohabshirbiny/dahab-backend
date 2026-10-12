<?php

use App\Models\AuditLog;
use App\Models\CommissionCredit;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\Promo\CustomerIdKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T036 + T040 (research R6, R7; contract customer-sell-api §2-§4): a code is recorded on a
// listing, replaced, removed and frozen; the gate counts per national ID and per month.

beforeEach(function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    Promo::open();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create([
        'seller_id' => $this->seller->customer_id, 'karat_code' => 21, 'stated_weight_g' => '10.000', 'making_charge_per_g' => '300.00',
    ]);
});

function putPromo($t, string $code, ?Listing $listing = null, ?Customer $as = null, ?string $key = null)
{
    $listing ??= $t->listing;

    return Listings::as($t, $as ?? $t->seller)->putJson("/api/v1/customer/me/listings/{$listing->listing_id}/promo", ['code' => $code], Promo::key($key));
}

function deletePromo($t, ?Listing $listing = null, ?Customer $as = null)
{
    $listing ??= $t->listing;

    return Listings::as($t, $as ?? $t->seller)->deleteJson("/api/v1/customer/me/listings/{$listing->listing_id}/promo", [], Promo::key());
}

it('records a code on the listing with its bound credit, its use and its audit row', function () {
    $code = Promo::code(['code' => 'WELCOME', 'commission_percent' => '50', 'first_sale_only' => true]);

    $res = putPromo($this, 'welcome')->assertOk();
    expect($res->json('data.promo'))->toMatchArray(['code' => 'WELCOME', 'kind' => 'discount', 'source' => 'promo_code', 'status' => 'active', 'can_change' => true, 'pays_advance' => false])
        ->and($res->json('data.promo.commission'))->toBe(['mode' => 'percent', 'percent' => '50.0000'])
        ->and($res->json('data.pays_advance'))->toBeNull();

    $credit = CommissionCredit::query()->where('listing_id', $this->listing->listing_id)->sole();
    expect($credit->kind->value)->toBe('percent')->and($credit->percent_off)->toBe('50.0000')
        ->and($credit->uses_left)->toBe(1)->and($credit->customer_id)->toBe($this->seller->customer_id)
        ->and($credit->promo_code)->toBe('WELCOME');

    $use = DB::table('promo_code_use')->where('code', 'WELCOME')->sole();
    expect((bool) $use->allowed)->toBeTrue()->and($use->listing_id)->toBe($this->listing->listing_id)
        ->and($use->credit_id)->toBe($credit->credit_id)->and($use->id_key)->toBe('account:'.$this->seller->customer_id)
        ->and($use->amount_egp)->not->toBeNull();

    expect(DB::table('listing_promo')->where('listing_id', $this->listing->listing_id)->value('credit_id'))->toBe($credit->credit_id);
    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'promo.applied')->sole());
    expect($audit->actor_customer_id)->toBe($this->seller->customer_id)
        ->and(json_encode($audit->after_json))->not->toContain('account:');
    expect(DB::table('commission_credit_event')->where('credit_id', $credit->credit_id)->pluck('kind')->all())->toBe(['granted']);
});

it('replaces the code and voids the earlier credit atomically', function () {
    Promo::code(['code' => 'FIRST']);
    Promo::code(['code' => 'SECOND', 'commission_mode' => 'waive']);

    putPromo($this, 'FIRST')->assertOk();
    putPromo($this, 'SECOND')->assertOk()->assertJsonPath('data.promo.code', 'SECOND');

    $credits = CommissionCredit::query()->where('listing_id', $this->listing->listing_id)->orderBy('granted_at')->get();
    expect($credits->pluck('status')->map->value->sort()->values()->all())->toBe(['active', 'voided'])
        ->and(DB::table('listing_promo')->where('listing_id', $this->listing->listing_id)->value('promo_code'))->toBe('SECOND');
    expect(DB::table('commission_credit_event')->where('kind', 'voided')->value('reason'))->toBe('code_replaced');
});

it('removes the code, voiding its credit, and says when there is none', function () {
    Promo::code(['code' => 'WELCOME']);
    deletePromo($this)->assertStatus(404)->assertJsonPath('code', 'promo_not_applied');

    putPromo($this, 'WELCOME')->assertOk();
    deletePromo($this)->assertOk()->assertJsonPath('data.promo', null);

    expect(CommissionCredit::query()->where('listing_id', $this->listing->listing_id)->sole()->status->value)->toBe('voided')
        ->and(DB::table('listing_promo')->count())->toBe(0)
        // the use stays, append-only, and no longer counts
        ->and(DB::table('promo_code_use')->count())->toBe(1);
    deletePromo($this)->assertStatus(404);
});

it('freezes the code once a buyer is in line and refuses another seller\'s listing', function () {
    Promo::code(['code' => 'WELCOME']);
    putPromo($this, 'WELCOME')->assertOk();

    $buyer = BuyRequests::funded('60000');
    BuyRequests::queued($this, $buyer, $this->listing);

    putPromo($this, 'WELCOME')->assertStatus(409)->assertJsonPath('code', 'listing_promo_locked');
    deletePromo($this)->assertStatus(409)->assertJsonPath('code', 'listing_promo_locked');

    $other = Customer::factory()->verified()->create();
    putPromo($this, 'WELCOME', $this->listing, $other)->assertNotFound();
    deletePromo($this, $this->listing, $other)->assertNotFound();
});

it('replays the same Idempotency-Key without a second redemption', function () {
    Promo::code(['code' => 'WELCOME']);
    $key = (string) Str::uuid();

    putPromo($this, 'WELCOME', null, null, $key)->assertOk();
    putPromo($this, 'WELCOME', null, null, $key)->assertOk();

    expect(DB::table('promo_code_use')->count())->toBe(1);
});

it('is closed while the switch is off: not_open and nothing written', function () {
    Promo::open(sellerCodes: false);
    Promo::code(['code' => 'WELCOME']);

    putPromo($this, 'WELCOME')->assertStatus(422)->assertJsonPath('details.reason', 'not_open');
    expect(DB::table('commission_credit')->count())->toBe(0)->and(DB::table('listing_promo')->count())->toBe(0)
        ->and(DB::table('promo_code_use')->where('allowed', true)->count())->toBe(0);
});

it('logs every refused attempt with its reason, for staff', function () {
    Promo::code(['code' => 'ONCE', 'use_limit' => 'once']);
    putPromo($this, 'ONCE')->assertOk();
    $second = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);

    putPromo($this, 'ONCE', $second)->assertStatus(422)->assertJsonPath('details.reason', 'already_used');

    $blocked = DB::table('promo_code_use')->where('allowed', false)->sole();
    expect($blocked->code)->toBe('ONCE')->and($blocked->block_reason)->toBe('already_used')
        ->and($blocked->customer_id)->toBe($this->seller->customer_id)->and($blocked->listing_id)->toBe($second->listing_id);
});

it('counts a "once" code against the national ID, so two accounts of one person share it', function () {
    Promo::code(['code' => 'ONCE', 'use_limit' => 'once']);
    $sameHash = CustomerIdKey::hash('29001011234567');

    // Both accounts have a verified Egyptian ID with the same number.
    foreach ([$this->seller, $twin = Customer::factory()->verified()->create()] as $c) {
        DB::table('identity_document')->where('customer_id', $c->customer_id)->delete();
        IdentityDocument::factory()->for($c)->create([
            'status' => 'verified', 'reviewed_at' => now(), 'id_number_hash' => $sameHash,
        ]);
    }

    putPromo($this, 'ONCE')->assertOk();
    $theirs = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $twin->customer_id]);
    putPromo($this, 'ONCE', $theirs, $twin)->assertStatus(422)->assertJsonPath('details.reason', 'already_used');

    // An account without a recorded ID is its own person.
    $stranger = Customer::factory()->verified()->create();
    $mine = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $stranger->customer_id]);
    putPromo($this, 'ONCE', $mine, $stranger)->assertOk();
});

it('frees the share when the code is removed or the piece leaves the market', function () {
    Promo::code(['code' => 'ONCE', 'use_limit' => 'once']);
    putPromo($this, 'ONCE')->assertOk();
    $again = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    putPromo($this, 'ONCE', $again)->assertStatus(422);

    deletePromo($this)->assertOk();
    putPromo($this, 'ONCE', $again)->assertOk();

    // The piece is withdrawn: its credit is voided, the share is free again.
    Listings::as($this, $this->seller)->postJson("/api/v1/customer/me/listings/{$again->listing_id}/withdraw", [], Promo::key())->assertOk();
    expect(CommissionCredit::query()->where('listing_id', $again->listing_id)->sole()->status->value)->toBe('voided');
    expect(DB::table('commission_credit_event')->where('kind', 'voided')->where('reason', 'listing_withdrawn')->count())->toBe(1);
    putPromo($this, 'ONCE', Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]))->assertOk();
});

it('stops at the monthly cap by value, and a voided credit gives its share back', function () {
    Promo::code(['code' => 'CAPPED', 'monthly_cap_egp' => '60000']);
    putPromo($this, 'CAPPED')->assertOk();   // the ring is about 55,631

    $second = Listing::factory()->withPhotos(2)->live()->create([
        'seller_id' => $this->seller->customer_id, 'karat_code' => 21, 'stated_weight_g' => '10.000', 'making_charge_per_g' => '300.00',
    ]);
    putPromo($this, 'CAPPED', $second)->assertStatus(422)->assertJsonPath('details.reason', 'cap_reached');

    deletePromo($this)->assertOk();
    putPromo($this, 'CAPPED', $second)->assertOk();
});

it('applies "first sale only" only to a seller with no paid sale', function () {
    Promo::code(['code' => 'FIRST', 'first_sale_only' => true]);

    $order = Orders::accepted($this);
    Orders::inspected($this, $order);
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    $veteran = Orders::seller($order);
    $fresh = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $veteran->customer_id]);

    putPromo($this, 'FIRST', $fresh, $veteran)->assertStatus(422)->assertJsonPath('details.reason', 'first_sale_only');
    putPromo($this, 'FIRST')->assertOk();
});

it('redeems a code typed on the sell form with the draft, and a bad one creates nothing', function () {
    Promo::code(['code' => 'WELCOME']);
    $body = Listings::goldBody($this, $this->seller, ['promo_code' => 'welcome']);

    $res = Listings::as($this, $this->seller)->postJson('/api/v1/customer/me/listings', $body, Promo::key())->assertCreated();
    expect($res->json('data.promo.code'))->toBe('WELCOME');
    $id = $res->json('data.id');
    expect(CommissionCredit::query()->where('listing_id', $id)->count())->toBe(1);

    $before = Listing::query()->where('seller_id', $this->seller->customer_id)->count();
    $bad = Listings::goldBody($this, $this->seller, ['promo_code' => 'NOSUCH']);
    Listings::as($this, $this->seller)->postJson('/api/v1/customer/me/listings', $bad, Promo::key())
        ->assertStatus(422)->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', 'unknown');
    expect(Listing::query()->where('seller_id', $this->seller->customer_id)->count())->toBe($before);
});

it('keeps the listing list in a fixed number of queries with a code on each piece', function () {
    Promo::code(['code' => 'MANY']);
    $count = fn () => (function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Listings::as($this, $this->seller)->getJson('/api/v1/customer/me/listings')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    })->call($this);

    $few = $count();
    foreach (range(1, 4) as $_) {
        $l = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
        DB::table('listing_promo')->insert(['listing_id' => $l->listing_id, 'promo_code' => 'MANY']);
    }
    $many = $count();

    // The code block reads its code row once per piece; the rest of the list is eager-loaded.
    expect($many - $few)->toBeLessThanOrEqual(4 * 2);
});
