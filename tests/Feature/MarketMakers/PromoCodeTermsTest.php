<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\PromoCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Promo;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 023 US5 / T029: the New-code form of the design, field by field; the old body still works.

const CODES = '/api/v1/dashboard/promo-codes';

beforeEach(function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
});

function newCode(array $body): TestResponse
{
    return test()->postJson(CODES, $body, Promo::key());
}

it('still creates a dealer code from the spec 019 body', function () {
    $dealer = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);

    newCode(['code' => 'ahmed-gold', 'tied_customer_id' => $dealer->customer_id, 'monthly_cap_egp' => 500000])
        ->assertCreated()
        ->assertJsonPath('data.code', 'AHMED-GOLD')
        ->assertJsonPath('data.kind', 'market_maker')
        ->assertJsonPath('data.what_it_does.commission.mode', 'waive')
        ->assertJsonPath('data.what_it_does.gives_spread', true)
        ->assertJsonPath('data.tied_to.audience', 'account')
        ->assertJsonPath('data.conditions.use_limit', 'unlimited')
        // the effective waiting age and approval of a dealer code follow the settings
        ->assertJsonPath('data.conditions.min_listed_days', 7)
        ->assertJsonPath('data.conditions.requires_approval', true);
});

it('creates the design\'s four rows through the API', function () {
    $karim = Customer::factory()->verified()->create(['customer_type' => 'market_maker', 'phone' => '+201001228842']);
    $hoda = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);

    newCode(['code' => 'MM-KARIM', 'audience' => 'account', 'tied_to' => '+201001228842', 'use_limit' => 'unlimited',
        'commission_mode' => 'waive', 'gives_spread' => true, 'min_listed_days' => 7, 'requires_approval' => true, 'monthly_cap_egp' => 500000])
        ->assertCreated()->assertJsonPath('data.kind', 'market_maker')
        ->assertJsonPath('data.tied_to.customer.phone_masked', '+20 10 ••8842');

    newCode(['code' => 'MM-HODA', 'audience' => 'account', 'tied_to' => $hoda->display_ref, 'commission_mode' => 'waive',
        'gives_spread' => true, 'min_listed_days' => 0, 'requires_approval' => false])
        ->assertCreated()->assertJsonPath('data.conditions.min_listed_days', 0)->assertJsonPath('data.conditions.requires_approval', false);

    newCode(['code' => 'WELCOME', 'audience' => 'anyone', 'use_limit' => 'once', 'commission_mode' => 'percent',
        'commission_percent' => 50, 'first_sale_only' => true])
        ->assertCreated()->assertJsonPath('data.kind', 'discount')
        ->assertJsonPath('data.tied_to.audience', 'anyone')->assertJsonPath('data.first_sale_only', true)
        ->assertJsonPath('data.what_it_does.commission.percent', '50.0000')
        ->assertJsonPath('data.conditions.min_listed_days', null);

    newCode(['code' => 'EID25', 'audience' => 'anyone', 'use_limit' => 'monthly', 'commission_mode' => 'percent',
        'commission_percent' => 25, 'ends_on' => '2026-12-31'])
        ->assertCreated()->assertJsonPath('data.conditions.ends_on', '2026-12-31')->assertJsonPath('data.conditions.use_limit', 'monthly');

    $rows = collect($this->getJson(CODES)->assertOk()->json('data'))->keyBy('code');
    expect($rows->keys()->sort()->values()->all())->toBe(['EID25', 'MM-HODA', 'MM-KARIM', 'WELCOME'])
        ->and($rows['WELCOME']['uses_this_month'])->toBe(0);
});

it('creates a first-sale code that pays the advance, open to anyone, for a first sale only', function () {
    newCode(['code' => 'FIRST-GOLD', 'audience' => 'anyone', 'pays_advance' => true])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'first_sale')
        ->assertJsonPath('data.first_sale_only', true)
        ->assertJsonPath('data.what_it_does.pays_advance', true)
        ->assertJsonPath('data.what_it_does.commission.mode', 'none');

    newCode(['code' => 'FIRST-ONE', 'kind' => 'first_sale', 'tied_to' => Customer::factory()->verified()->create()->display_ref])
        ->assertCreated()->assertJsonPath('data.what_it_does.pays_advance', true);
});

it('refuses terms that contradict each other', function () {
    $dealer = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);
    $ordinary = Customer::factory()->verified()->create();

    foreach ([
        // a percentage needs its figure, and only then
        ['code' => 'A-1', 'audience' => 'anyone', 'commission_mode' => 'percent'],
        ['code' => 'A-2', 'audience' => 'anyone', 'commission_mode' => 'waive', 'commission_percent' => 10],
        ['code' => 'A-3', 'audience' => 'anyone', 'commission_mode' => 'percent', 'commission_percent' => 100],
        // a discount that does nothing
        ['code' => 'A-4', 'audience' => 'anyone'],
        // the spread can only go to a dealer
        ['code' => 'A-5', 'audience' => 'anyone', 'commission_mode' => 'waive', 'gives_spread' => true],
        // one account needs its account; anyone has none
        ['code' => 'A-6', 'audience' => 'account', 'commission_mode' => 'waive'],
        ['code' => 'A-7', 'audience' => 'anyone', 'tied_customer_id' => $ordinary->customer_id, 'commission_mode' => 'waive'],
        // a dealer code waives and cannot also pay the advance
        ['code' => 'A-8', 'tied_customer_id' => $dealer->customer_id, 'pays_advance' => true, 'commission_mode' => 'waive'],
        ['code' => 'A-9', 'tied_customer_id' => $dealer->customer_id, 'commission_mode' => 'percent', 'commission_percent' => 50],
        // dates, age, characters
        ['code' => 'B-1', 'audience' => 'anyone', 'commission_mode' => 'waive', 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-01'],
        ['code' => 'B-2', 'audience' => 'anyone', 'commission_mode' => 'waive', 'min_listed_days' => 4000],
        ['code' => 'no spaces', 'audience' => 'anyone', 'commission_mode' => 'waive'],
    ] as $body) {
        newCode($body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    expect(PromoCode::query()->count())->toBe(0);
});

it('answers the account errors with their own codes', function () {
    $ordinary = Customer::factory()->verified()->create();

    newCode(['code' => 'NOBODY', 'tied_to' => '+201999999999', 'commission_mode' => 'waive'])
        ->assertStatus(422)->assertJsonPath('code', 'promo_account_not_found');
    newCode(['code' => 'NOTMM', 'kind' => 'market_maker', 'tied_customer_id' => $ordinary->customer_id])
        ->assertStatus(422)->assertJsonPath('code', 'promo_customer_not_market_maker');

    newCode(['code' => 'TAKEN', 'audience' => 'anyone', 'commission_mode' => 'waive'])->assertCreated();
    newCode(['code' => 'taken', 'audience' => 'anyone', 'commission_mode' => 'waive'])
        ->assertStatus(409)->assertJsonPath('code', 'promo_code_exists');

    // A code never equals someone's referral code.
    DB::table('referral_code')->insert(['customer_id' => $ordinary->customer_id, 'code' => 'K7QH3M9T']);
    newCode(['code' => 'K7QH3M9T', 'audience' => 'anyone', 'commission_mode' => 'waive'])
        ->assertStatus(409)->assertJsonPath('code', 'promo_code_exists');
});

it('changes only the cap and the switch, and freezes the terms with DH020', function () {
    newCode(['code' => 'WELCOME', 'audience' => 'anyone', 'commission_mode' => 'percent', 'commission_percent' => 50])->assertCreated();

    $this->patchJson(CODES.'/WELCOME', ['monthly_cap_egp' => 250000, 'active' => false], Promo::key())
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.monthly_cap_egp', '250000.0000');

    expect(fn () => DB::table('promo_code')->where('code', 'WELCOME')->update(['commission_percent' => 60]))
        ->toThrow(QueryException::class);
});

it('lists the uses of a code with the figures of each', function () {
    $code = Promo::code(['code' => 'WELCOME']);
    $customer = Customer::factory()->verified()->create();
    DB::table('promo_code_use')->insert([
        'code' => $code->code, 'customer_id' => $customer->customer_id, 'allowed' => false, 'block_reason' => 'already_used',
        'id_key' => 'account:x',
    ]);

    $row = $this->getJson(CODES.'/WELCOME/uses')->assertOk()->json('data.0');
    expect($row['allowed'])->toBeFalse()->and($row['block_reason'])->toBe('already_used')
        ->and($row)->toHaveKeys(['value_egp', 'listing_ref', 'buy_request_ref', 'credit_status', 'reduction_egp'])
        ->and(json_encode($row))->not->toContain('account:x');
});

it('reads a capped code as value this month', function () {
    $code = Promo::code(['code' => 'CAPPED', 'monthly_cap_egp' => '500000']);
    $customer = Customer::factory()->verified()->create();
    DB::table('promo_code_use')->insert([
        'code' => $code->code, 'customer_id' => $customer->customer_id, 'allowed' => true, 'amount_egp' => '312400', 'id_key' => 'k',
    ]);

    $row = collect($this->getJson(CODES)->assertOk()->json('data'))->firstWhere('code', 'CAPPED');
    expect($row['uses_this_month'])->toBe(1)->and($row['value_this_month_egp'])->toBe('312400.0000')
        ->and($row['monthly_cap_egp'])->toBe('500000.0000');
});
