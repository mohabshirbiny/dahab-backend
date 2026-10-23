<?php

use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T035 (research R24; contract customer-sell-api §1): the preview is stateless and answers
// with the same resolver the settlement uses.

beforeEach(function () {
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
    Promo::open();
    $this->body = ['category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'making_charge_per_g' => '300.00'];
});

function preview($t, array $extra = [], ?Customer $as = null)
{
    return Listings::as($t, $as ?? $t->seller)->postJson(Promo::PREVIEW_URL, array_merge($t->body, $extra));
}

it('answers a percentage code with the commission before and after, and records nothing', function () {
    Promo::code(['code' => 'WELCOME', 'commission_percent' => '50', 'first_sale_only' => true]);

    $res = preview($this, ['code' => 'welcome'])->assertOk();
    $data = $res->json('data');

    expect($data['code'])->toMatchArray(['value' => 'WELCOME', 'kind' => 'discount', 'source' => 'promo_code', 'first_sale_only' => true])
        ->and($data['code']['commission'])->toBe(['mode' => 'percent', 'percent' => '50.0000'])
        ->and($data['waiver'])->toMatchArray(['kind' => 'credit', 'source' => 'promo_code', 'credit_id' => null])
        ->and($data['commission'])->toBe([
            // Package C: net figures, and what the seller pays and saves VAT included.
            'ordinary_egp' => '516.0000', 'charged_egp' => '258.0000', 'vat_egp' => '42.0000',
            'saved_egp' => '258.0000', 'ordinary_total_egp' => '600.0000', 'charged_total_egp' => '300.0000',
            'saved_total_egp' => '300.0000', 'minimum_egp' => '200.0000', 'floor_applied' => false,
        ])
        ->and(bcsub($data['you_receive']['with_promo_egp'], $data['you_receive']['without_promo_egp'], 4))->toBe('300.0000') // package C: the seller saves exactly the 300 off, VAT included
        ->and($data['pays_advance'])->toBeNull()
        ->and($data['indicative'])->toBeTrue();

    // No side effects: no use, no credit, no listing code.
    expect(DB::table('promo_code_use')->count())->toBe(0)
        ->and(DB::table('commission_credit')->count())->toBe(0)
        ->and(DB::table('listing_promo')->count())->toBe(0);
});

it('says when the minimum limited the saving', function () {
    Promo::code(['code' => 'BIG', 'commission_percent' => '99']);

    $data = preview($this, ['code' => 'BIG'])->assertOk()->json('data.commission');
    expect($data['charged_egp'])->toBe('172.0000')->and($data['saved_egp'])->toBe('344.0000')->and($data['saved_total_egp'])->toBe('400.0000')->and($data['floor_applied'])->toBeTrue();
});

it('shows a waive code as nothing charged', function () {
    Promo::code(['code' => 'FREE', 'commission_mode' => 'waive']);

    $data = preview($this, ['code' => 'FREE'])->assertOk()->json('data');
    expect($data['commission']['charged_egp'])->toBe('0.0000')->and($data['commission']['floor_applied'])->toBeFalse()
        ->and($data['code']['commission']['mode'])->toBe('waive');
});

it('shows a credit already in the pool without any code', function () {
    Promo::credit($this->seller, null, ['kind' => 'amount', 'amount_egp' => '200', 'amount_left_egp' => '200']);

    $data = preview($this)->assertOk()->json('data');
    expect($data['code'])->toBeNull()
        ->and($data['waiver']['kind'])->toBe('credit')->and($data['waiver']['source'])->toBe('referral')
        ->and($data['commission']['charged_egp'])->toBe('344.0000');
});

it('never reveals a private code and refuses what cannot be used, with the reason', function () {
    $other = Customer::factory()->verified()->create();
    Promo::code(['code' => 'PRIVATE', 'audience' => 'account', 'tied_customer_id' => $other->customer_id]);
    Promo::code(['code' => 'LATER', 'starts_on' => now('Africa/Cairo')->addDays(3)->toDateString()]);
    Promo::code(['code' => 'OVER', 'ends_on' => now('Africa/Cairo')->subDay()->toDateString(), 'starts_on' => now('Africa/Cairo')->subDays(5)->toDateString()]);
    Promo::code(['code' => 'OFF', 'is_active' => false]);
    Promo::code(['code' => 'DEALER', 'kind' => 'market_maker', 'audience' => 'account', 'tied_customer_id' => $other->customer_id,
        'commission_mode' => 'waive', 'gives_spread' => true]);

    foreach ([
        'PRIVATE' => 'unknown', 'NOSUCH' => 'unknown', 'OFF' => 'unknown', 'DEALER' => 'unknown',
        'LATER' => 'not_started', 'OVER' => 'ended',
    ] as $code => $reason) {
        preview($this, ['code' => $code])->assertStatus(422)
            ->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', $reason);
    }

    // An account-tied code works for its own account.
    preview($this, ['code' => 'PRIVATE'], $other)->assertOk();
    expect(DB::table('promo_code_use')->count())->toBe(0);
});

it('answers not_open for every code while the programmes are off, and still shows the pool', function () {
    Promo::open(sellerCodes: false);
    Promo::code(['code' => 'WELCOME']);

    preview($this, ['code' => 'WELCOME'])->assertStatus(422)->assertJsonPath('details.reason', 'not_open');
    preview($this, ['code' => 'NOSUCH'])->assertStatus(422)->assertJsonPath('details.reason', 'not_open');
    preview($this)->assertOk()->assertJsonPath('data.waiver.kind', 'none');
});

it('reports the first-sale advance only for a code that pays it or an enabled seller', function () {
    Promo::code(['code' => 'EARLY', 'kind' => 'first_sale', 'pays_advance' => true, 'first_sale_only' => true, 'commission_mode' => 'none']);
    Promo::code(['code' => 'PLAIN']);

    preview($this, ['code' => 'PLAIN'])->assertOk()->assertJsonPath('data.pays_advance', null);

    // The switch is off by default: the card says so instead of promising money.
    preview($this, ['code' => 'EARLY'])->assertOk()
        ->assertJsonPath('data.pays_advance.available', false)->assertJsonPath('data.pays_advance.reason', 'switch_off')
        ->assertJsonPath('data.pays_advance.cap_egp', '100000.0000')
        ->assertJsonPath('data.waiver.kind', 'none');

    Promo::set(SettingKey::PAYOUT_FIRST_SALE_ENABLED, true);
    preview($this, ['code' => 'EARLY'])->assertOk()->assertJsonPath('data.pays_advance.available', true)
        ->assertJsonPath('data.pays_advance.reason', null);

    // A seller who is not on their first gold sale is told so.
    DB::table('first_sale_enablement')->insert(['seller_id' => $this->seller->customer_id, 'enabled_by' => SystemActor::id(), 'reason' => 'Enabled by staff for this test']);
    preview($this)->assertOk()->assertJsonPath('data.pays_advance.available', true);
});

it('promises the first sale paid ahead with no code once the switch is on, and only for gold', function () {
    Promo::set(SettingKey::PAYOUT_FIRST_SALE_ENABLED, true);

    preview($this)->assertOk()->assertJsonPath('data.pays_advance.available', true)
        ->assertJsonPath('data.pays_advance.reason', null)->assertJsonPath('data.pays_advance.cap_egp', '100000.0000');

    // A diamond is never paid ahead, so nothing is promised.
    Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, ['category' => 'diamond', 'asking_price' => '120000.00'])
        ->assertOk()->assertJsonPath('data.pays_advance', null);

    // The ceiling reached: the seller is told it cannot be paid early now.
    Promo::set(SettingKey::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP, 0);
    preview($this)->assertOk()->assertJsonPath('data.pays_advance.available', false)
        ->assertJsonPath('data.pays_advance.reason', 'ceiling');
});

it('validates the piece figures', function () {
    preview($this, ['karat' => null, 'weight_g' => null])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, ['category' => 'diamond', 'karat' => 21, 'asking_price' => '1000'])
        ->assertStatus(422);
    Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, ['category' => 'diamond', 'asking_price' => '120000.00'])
        ->assertOk()->assertJsonPath('data.commission.ordinary_egp', '5160.0000');
});

it('is limited to ten checks a minute', function () {
    for ($i = 0; $i < 10; $i++) {
        preview($this)->assertOk();
    }
    preview($this)->assertStatus(429);
});

it('is for a verified customer who is not suspended', function () {
    Listings::anonymous($this)->postJson(Promo::PREVIEW_URL, $this->body)->assertUnauthorized();

    $pending = Customer::factory()->pendingVerification()->create();
    preview($this, [], $pending)->assertForbidden();
});
