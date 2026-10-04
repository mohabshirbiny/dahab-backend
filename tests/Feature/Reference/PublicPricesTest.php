<?php

use App\Enums\PieceCategory;
use App\Enums\SeedRole;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Finance;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 FR-021 (Clarification): today's prices and the seller's estimate
// for everyone, from the spec 005 calculator; indicative; nothing locked.

const PRICES_URL = '/api/v1/reference/gold-prices';
const QUOTE_URL = '/api/v1/reference/quote';

it('gives per enabled karat what sellers get and buyers pay, the same as the Dashboard, and nothing else', function () {
    Orders::workedPrices();

    $res = $this->getJson(PRICES_URL)->assertOk()->assertHeader('Cache-Control', 'max-age=30, public');
    $k21 = collect($res->json('data.karats'))->firstWhere('code', 21);
    expect($k21)->toBe(['code' => 21, 'label' => '21K', 'sellers_get' => '5236.8750', 'buyers_pay' => '5263.1250'])
        ->and(collect($res->json('data.karats'))->pluck('code')->all())->toBe([24, 21, 18])
        ->and($res->json('data'))->not->toHaveKey('bid_24k')
        ->and($res->json('data.feed_state'))->toBeIn(['live', 'stale']);

    Finance::staff($this, SeedRole::FINANCE);
    $dash = collect($this->getJson('/api/v1/dashboard/gold-prices/current')->assertOk()->json('data.karats'))->firstWhere('karat_code', 21)
        ?? collect($this->getJson('/api/v1/dashboard/gold-prices/current')->json('data.karats'))->firstWhere('code', 21);
    expect($dash['sellers_get'] ?? null)->toBe('5236.8750');
});

it('says when a manual price is in force, and refuses when there is no price', function () {
    $this->getJson(PRICES_URL)->assertStatus(409)->assertJsonPath('code', 'price_unavailable');
    $this->getJson(QUOTE_URL.'?category=gold&karat=21&weight_g=10')->assertStatus(409)->assertJsonPath('code', 'price_unavailable');

    // The feed is down (not configured here): Finance enters a price by hand.
    Finance::staff($this, SeedRole::FINANCE);
    $this->postJson('/api/v1/dashboard/gold-prices/manual', ['bid_24k' => '5994', 'ask_24k' => '5994', 'reason' => 'The feed is down this morning.'],
        Finance::key())->assertCreated();
    app('auth')->forgetGuards();
    $this->getJson(PRICES_URL)->assertOk()->assertJsonPath('data.feed_state', 'manual');
});

it('quotes a gold piece exactly as the calculator pays the seller', function () {
    Orders::workedPrices();
    $ctx = app(PricingContext::class);
    $expected = $ctx->breakdown(Piece::gold($ctx->pricing(21), '10.000', '300'));

    $this->getJson(QUOTE_URL.'?category=gold&karat=21&weight_g=10.000&making_per_g=300')->assertOk()
        ->assertJsonPath('data.payout', $expected->sellerProceeds)
        ->assertJsonPath('data.commission', $expected->commission)->assertJsonPath('data.vat', $expected->vat)
        ->assertJsonPath('data.making_back', '3000.0000')->assertJsonPath('data.gold_value', '52368.7500')
        ->assertJsonPath('data.rate_per_gram', '5236.8750')->assertJsonPath('data.indicative', true);
});

it('quotes gold with a diamond and a diamond on the asking price', function () {
    Orders::workedPrices();
    $ctx = app(PricingContext::class);

    $mixed = $ctx->breakdown(Piece::goldWithDiamond($ctx->pricing(21), '5.000', '60000'));
    $this->getJson(QUOTE_URL.'?category=gold_with_diamond&karat=21&weight_g=5&asking_price=60000')->assertOk()
        ->assertJsonPath('data.payout', $mixed->sellerProceeds)->assertJsonPath('data.gold_value', $mixed->protectedGoldValue);

    $stone = $ctx->breakdown(Piece::diamond('80000'));
    $this->getJson(QUOTE_URL.'?category=diamond&asking_price=80000')->assertOk()
        ->assertJsonPath('data.payout', $stone->sellerProceeds)->assertJsonPath('data.gold_value', '0.0000');
    expect(PieceCategory::DIAMOND->value)->toBe('diamond');
});

it('validates the piece', function () {
    Orders::workedPrices();

    $this->getJson(QUOTE_URL.'?category=gold&karat=22&weight_g=10')->assertStatus(422)->assertJsonValidationErrors('karat');
    $this->getJson(QUOTE_URL.'?category=gold&karat=21&weight_g=0')->assertStatus(422)->assertJsonValidationErrors('weight_g');
    $this->getJson(QUOTE_URL.'?category=gold&karat=21&weight_g=10001')->assertStatus(422)->assertJsonValidationErrors('weight_g');
    $this->getJson(QUOTE_URL.'?category=gold&weight_g=10')->assertStatus(422)->assertJsonValidationErrors('karat');
    $this->getJson(QUOTE_URL.'?category=gold_with_diamond&karat=21&weight_g=5')->assertStatus(422)->assertJsonValidationErrors('asking_price');
    $this->getJson(QUOTE_URL.'?category=diamond')->assertStatus(422)->assertJsonValidationErrors('asking_price');
    $this->getJson(QUOTE_URL.'?category=silver')->assertStatus(422)->assertJsonValidationErrors('category');
});
