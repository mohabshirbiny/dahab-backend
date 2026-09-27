<?php

use App\Enums\SeedRole;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\Staff;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 005 US6 / FR-030: the current prices, feed state, pending request and
// every karat's prices; the price history and a server-side preview.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->token = staffAccessToken(Staff::factory()->role(SeedRole::OPERATIONS)->create());
    config(['services.gold_feed.password' => null]);
});

it('has no price and no karats before the first price', function () {
    $this->bearer($this->token)->getJson('/api/v1/dashboard/gold-prices/current')
        ->assertOk()
        ->assertJsonPath('data.price', null)
        ->assertJsonPath('data.feed.state', 'not_configured')
        ->assertJsonPath('data.pending', null)
        ->assertJsonPath('data.karats', []);
});

it('shows the current price, the pending request and every karat, disabled ones included', function () {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
    $staff = Staff::factory()->create();
    ManualGoldPrice::query()->create([
        'bid_24k' => '9000', 'ask_24k' => '9050', 'requires_confirmation' => true, 'reason' => 'Feed down',
        'entered_by' => $staff->staff_id, 'status' => 'pending', 'expires_at' => now()->addDay(),
    ]);

    $data = $this->bearer($this->token)->getJson('/api/v1/dashboard/gold-prices/current')->assertOk()->json('data');

    expect($data['price'])->toMatchArray(['source' => 'feed', 'bid_24k' => '7944.0000', 'ask_24k' => '7990.0000', 'entered_by' => null])
        ->and($data['pending']['status'])->toBe('pending')
        ->and(collect($data['karats'])->pluck('code')->all())->toBe([24, 22, 21, 20, 18]);

    expect(collect($data['karats'])->firstWhere('code', 21))->toBe([
        'code' => 21, 'purity' => '0.87500', 'is_enabled' => true,
        'market_bid' => '6957.9580', 'market_ask' => '6998.2482',
        'sellers_get' => '6942.9580', 'buyers_pay' => '7013.2482', 'difference' => '70.2902',
        'adjustments' => ['buy' => ['kind' => 'fixed', 'value' => '-15.0000'], 'sell' => ['kind' => 'fixed', 'value' => '15.0000']],
        'inverted' => false,
    ]);
});

it('hides a pending request whose window has passed', function () {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
    ManualGoldPrice::query()->create([
        'bid_24k' => '9000', 'ask_24k' => '9050', 'requires_confirmation' => true, 'reason' => 'Feed down',
        'entered_by' => Staff::factory()->create()->staff_id, 'status' => 'pending', 'expires_at' => now()->subMinute(),
    ]);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/gold-prices/current')->assertJsonPath('data.pending', null);
});

it('lists the price history newest first', function () {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7900', 'ask_24k' => '7950', 'recorded_by' => SystemActor::id(), 'effective_at' => now()->subHour()]);
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/gold-prices?per_page=1')
        ->assertOk()->assertJsonPath('data.0.bid_24k', '7944.0000')->assertJsonPath('meta.total', 2);
});

it('previews prices and adjustments without saving anything', function () {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);

    $data = $this->bearer($this->token)->postJson('/api/v1/dashboard/gold-prices/preview', [
        'change_pct' => '10',
        'adjustments' => ['21' => ['buy' => ['kind' => 'percent', 'value' => '-1']]],
    ])->assertOk()->json('data');

    expect($data['bid_24k'])->toBe('8738.4000')
        ->and($data['deviation_pct'])->toBe('10.0000')
        ->and(collect($data['karats'])->firstWhere('code', 21)['adjustments']['buy'])->toBe(['kind' => 'percent', 'value' => '-1.0000'])
        ->and(GoldPrice::query()->count())->toBe(1);

    $this->bearer($this->token)->postJson('/api/v1/dashboard/gold-prices/preview', [])->assertOk();
});

it('refuses a preview with no price at all', function () {
    $this->bearer($this->token)->postJson('/api/v1/dashboard/gold-prices/preview', [])->assertStatus(409)->assertJsonPath('code', 'no_gold_price');
});

it('needs pricing.view', function () {
    $verifier = staffAccessToken(Staff::factory()->role(SeedRole::VERIFICATION)->create());

    $this->bearer($verifier)->getJson('/api/v1/dashboard/gold-prices/current')->assertForbidden();
});
