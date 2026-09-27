<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\GoldPrice;
use App\Models\KaratPriceAdjustment;
use App\Models\KaratPriceAdjustmentHistory;
use App\Models\Staff;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 005 US3: per-karat, per-side adjustments, fixed or percent (FR-017).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->token = staffAccessToken($this->finance);
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
});

function adjustmentBody(array $buy, array $sell, string $reason = 'Market moved'): array
{
    return ['buy' => $buy, 'sell' => $sell, 'reason' => $reason];
}

it('changes both sides with a reason, keeps history per side, and shows the new prices', function () {
    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'percent', 'value' => '-1.5'], ['kind' => 'fixed', 'value' => '10'],
    ))->assertOk()
        ->assertJsonPath('data.code', 21)
        ->assertJsonPath('data.adjustments.buy', ['kind' => 'percent', 'value' => '-1.5000'])
        ->assertJsonPath('data.sellers_get', '6853.5886')
        ->assertJsonPath('data.buyers_pay', '7008.2482');

    expect(KaratPriceAdjustmentHistory::query()->count())->toBe(2)
        ->and(KaratPriceAdjustmentHistory::query()->where('side', 'buy')->sole()->reason)->toBe('Market moved')
        ->and(AuditLog::query()->where('action', 'pricing.adjustment.changed')->count())->toBe(2);
});

it('writes history only for the side that changed', function () {
    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'fixed', 'value' => '-15'], ['kind' => 'fixed', 'value' => '20'],
    ))->assertOk();

    expect(KaratPriceAdjustmentHistory::query()->sole()->side->value)->toBe('sell');
});

it('refuses an adjustment that inverts the karat at the current price', function () {
    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'fixed', 'value' => '100'], ['kind' => 'fixed', 'value' => '-100'],
    ))->assertUnprocessable()->assertJsonPath('code', 'price_inverted');

    expect(KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'buy')->value('value'))->toBe('-15.0000');
});

it('validates kinds, values and the reason', function () {
    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'percent', 'value' => '-100'], ['kind' => 'fixed', 'value' => '10'],
    ))->assertUnprocessable()->assertJsonValidationErrors('buy.value');

    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'double', 'value' => '1'], ['kind' => 'fixed', 'value' => '10'],
    ))->assertUnprocessable()->assertJsonValidationErrors('buy.kind');

    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/21/adjustments', ['buy' => ['kind' => 'fixed', 'value' => '-10'], 'sell' => ['kind' => 'fixed', 'value' => '10']])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/9/adjustments', adjustmentBody(
        ['kind' => 'fixed', 'value' => '-10'], ['kind' => 'fixed', 'value' => '10'],
    ))->assertNotFound();
});

it('keeps adjustments to the rates permission and lists the history', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    $this->bearer(staffAccessToken($coo))->putJson('/api/v1/dashboard/karats/21/adjustments', adjustmentBody(
        ['kind' => 'fixed', 'value' => '-10'], ['kind' => 'fixed', 'value' => '10'],
    ))->assertForbidden();

    $this->bearer($this->token)->putJson('/api/v1/dashboard/karats/18/adjustments', adjustmentBody(
        ['kind' => 'fixed', 'value' => '-12'], ['kind' => 'fixed', 'value' => '15'],
    ))->assertOk();

    $this->bearer(staffAccessToken($coo))->getJson('/api/v1/dashboard/price-adjustments/history?karat=18')
        ->assertOk()
        ->assertJsonPath('data.0.karat_code', 18)
        ->assertJsonPath('data.0.old', ['kind' => 'fixed', 'value' => '-15.0000'])
        ->assertJsonPath('data.0.new', ['kind' => 'fixed', 'value' => '-12.0000'])
        ->assertJsonPath('data.0.changed_by.id', $this->finance->staff_id);
});

it('creates a new karat with zero adjustments through the Dashboard', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    $this->bearer(staffAccessToken($coo))->postJson('/api/v1/dashboard/karats', ['code' => 14, 'purity' => '0.585'])->assertCreated();

    expect(KaratPriceAdjustment::query()->where('karat_code', 14)->orderBy('side')->pluck('value', 'side')->all())
        ->toBe(['buy' => '0.0000', 'sell' => '0.0000']);
});
