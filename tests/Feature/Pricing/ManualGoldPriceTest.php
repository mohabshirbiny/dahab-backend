<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\PriceFeedStatus;
use App\Models\Setting;
use App\Models\Staff;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 005 US2: a manual gold price while the feed is down, with a dynamic
// confirmation above manualprice.confirm_deviation_pct (FR-012–FR-015).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->financeToken = staffAccessToken($this->finance);
    config(['services.gold_feed' => ['base_url' => null, 'username' => null, 'password' => null, 'timeout' => 10]]);
});

function enterPrice($test, string $token, array $body)
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->postJson('/api/v1/dashboard/gold-prices/manual', $body + ['reason' => 'Feed down since 11:00, price by phone']);
}

function seedCurrentPrice(string $bid = '7944', string $ask = '7990'): GoldPrice
{
    return GoldPrice::query()->create([
        'source' => 'feed', 'bid_24k' => $bid, 'ask_24k' => $ask, 'recorded_by' => SystemActor::id(), 'effective_at' => now()->subHour(),
    ]);
}

it('takes the first price at once, with no confirmation', function () {
    enterPrice($this, $this->financeToken, ['bid_24k' => '7944', 'ask_24k' => '7990'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'effective')
        ->assertJsonPath('data.requires_confirmation', false)
        ->assertJsonPath('data.deviation_pct', null)
        ->assertJsonPath('data.entered_by.id', $this->finance->staff_id);

    $current = GoldPrice::current();
    expect($current->source->value)->toBe('manual')
        ->and((string) $current->bid_24k)->toBe('7944.0000')
        ->and($current->recorded_by)->toBe($this->finance->staff_id);

    $audit = AuditLog::query()->where('action', 'pricing.manual_price.entered')->sole();
    expect($audit->reason)->toBe('Feed down since 11:00, price by phone');
});

it('takes a price within the threshold at once and records the previous price and deviation', function () {
    $previous = seedCurrentPrice();

    enterPrice($this, $this->financeToken, ['bid_24k' => '8100', 'ask_24k' => '8150'])
        ->assertCreated()->assertJsonPath('data.status', 'effective')->assertJsonPath('data.deviation_pct', '2.0025');

    expect(ManualGoldPrice::query()->sole()->previous_gold_price_id)->toBe($previous->gold_price_id)
        ->and((string) GoldPrice::current()->ask_24k)->toBe('8150.0000');
});

it('holds a large jump as pending until someone else confirms it', function () {
    seedCurrentPrice();

    $pending = enterPrice($this, $this->financeToken, ['bid_24k' => '9000', 'ask_24k' => '9050'])
        ->assertStatus(202)
        ->assertJsonPath('code', 'manual_price_confirm_required')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requires_confirmation', true)
        ->json('data');

    expect((string) GoldPrice::current()->bid_24k)->toBe('7944.0000');

    $this->bearer($this->financeToken)->postJson("/api/v1/dashboard/gold-prices/manual/{$pending['id']}/confirm")
        ->assertForbidden()->assertJsonPath('code', 'confirmer_must_differ');

    $this->bearer(staffAccessToken($this->ceo))->postJson("/api/v1/dashboard/gold-prices/manual/{$pending['id']}/confirm")
        ->assertOk()->assertJsonPath('data.status', 'effective')->assertJsonPath('data.confirmed_by.id', $this->ceo->staff_id);

    expect((string) GoldPrice::current()->bid_24k)->toBe('9000.0000')
        ->and(GoldPrice::current()->recorded_by)->toBe($this->ceo->staff_id)
        ->and(AuditLog::query()->where('action', 'pricing.manual_price.confirmed')->count())->toBe(1);
});

it('lets the same person confirm when the setting allows it', function () {
    seedCurrentPrice();
    Setting::query()->whereKey('manualprice.confirmer_must_differ')->update(['value_bool' => false]);

    $id = enterPrice($this, $this->financeToken, ['bid_24k' => '9000', 'ask_24k' => '9050'])->assertStatus(202)->json('data.id');

    $this->bearer($this->financeToken)->postJson("/api/v1/dashboard/gold-prices/manual/{$id}/confirm")->assertOk();
});

it('needs the confirm permission, whoever entered it', function () {
    seedCurrentPrice();
    $id = enterPrice($this, $this->financeToken, ['bid_24k' => '9000', 'ask_24k' => '9050'])->json('data.id');
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    $this->bearer(staffAccessToken($coo))->postJson("/api/v1/dashboard/gold-prices/manual/{$id}/confirm")
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('refuses to confirm a superseded, lapsed or overtaken request', function (string $case) {
    seedCurrentPrice();
    $id = enterPrice($this, $this->financeToken, ['bid_24k' => '9000', 'ask_24k' => '9050'])->json('data.id');

    match ($case) {
        'superseded' => enterPrice($this, $this->financeToken, ['bid_24k' => '9100', 'ask_24k' => '9150'])->assertStatus(202),
        'lapsed' => $this->travel(25)->hours(),
        'overtaken' => seedCurrentPrice('7950', '7995'),
    };

    $this->bearer(staffAccessToken($this->ceo))->postJson("/api/v1/dashboard/gold-prices/manual/{$id}/confirm")
        ->assertStatus(409)->assertJsonPath('code', 'manual_price_not_pending');
})->with(['superseded', 'lapsed', 'overtaken']);

it('accepts a percentage change from the current pair', function () {
    seedCurrentPrice('8000', '8100');

    enterPrice($this, $this->financeToken, ['change_pct' => '-2.5'])
        ->assertCreated()->assertJsonPath('data.bid_24k', '7800.0000')->assertJsonPath('data.ask_24k', '7897.5000');
});

it('refuses a percentage change with no current price', function () {
    enterPrice($this, $this->financeToken, ['change_pct' => '1'])->assertStatus(409)->assertJsonPath('code', 'no_gold_price');
});

it('validates the prices and the reason', function () {
    enterPrice($this, $this->financeToken, ['bid_24k' => '8000', 'ask_24k' => '7900'])->assertUnprocessable()->assertJsonValidationErrors('ask_24k');
    enterPrice($this, $this->financeToken, ['bid_24k' => '0', 'ask_24k' => '10'])->assertUnprocessable()->assertJsonValidationErrors('bid_24k');

    $this->bearer($this->financeToken)->postJson('/api/v1/dashboard/gold-prices/manual', ['bid_24k' => '7944', 'ask_24k' => '7990'])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    expect(GoldPrice::query()->count())->toBe(0);
});

it('refuses a manual price while the feed is healthy', function () {
    config(['services.gold_feed' => ['base_url' => 'https://feed.test', 'username' => 'dummy', 'password' => 'dummy', 'timeout' => 10]]);
    PriceFeedStatus::query()->create(['provider' => PriceFeedStatus::PROVIDER, 'last_success_at' => now()->subMinute()]);

    enterPrice($this, $this->financeToken, ['bid_24k' => '7944', 'ask_24k' => '7990'])
        ->assertStatus(409)->assertJsonPath('code', 'price_feed_healthy');

    // Stale beyond pricefeed.stale_after_minutes: the feed counts as down.
    PriceFeedStatus::query()->update(['last_success_at' => now()->subMinutes(6)]);
    enterPrice($this, $this->financeToken, ['bid_24k' => '7944', 'ask_24k' => '7990'])->assertCreated();
});

it('is refused to staff without the enter permission', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    enterPrice($this, staffAccessToken($coo), ['bid_24k' => '7944', 'ask_24k' => '7990'])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
    expect(DB::table('manual_gold_price')->count())->toBe(0);
});
