<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\GoldPrice;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Staff;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 005 US3 (rates) and US5 (operations): settings change with a reason,
// by the group's permission, kept in history and audited (FR-002–FR-004).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create();
});

function changeSetting($test, Staff $staff, string $key, array $body)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->patchJson("/api/v1/dashboard/settings/{$key}", $body);
}

it('lets Finance change a rate with a reason, kept in history and audited', function () {
    changeSetting($this, $this->finance, 'commission.gold_pct', ['value' => '18', 'reason' => 'Autumn promotion'])
        ->assertOk()
        ->assertJsonPath('data.key', 'commission.gold_pct')
        ->assertJsonPath('data.value', '18.0000')
        ->assertJsonPath('data.group', 'rates')
        ->assertJsonPath('data.updated_by.id', $this->finance->staff_id);

    $history = SettingHistory::query()->sole();
    expect((string) $history->old_numeric)->toBe('20.0000')
        ->and((string) $history->new_numeric)->toBe('18.0000')
        ->and($history->reason)->toBe('Autumn promotion')
        ->and(AuditLog::query()->where('action', 'pricing.setting.changed')->sole()->reason)->toBe('Autumn promotion');
});

it('applies a new rate to the very next calculation', function () {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '5994', 'ask_24k' => '5994', 'recorded_by' => SystemActor::id()]);
    $context = app(PricingContext::class);
    $piece = Piece::gold($context->pricing(21), '10.000', '300');

    expect($context->breakdown($piece)->commission)->toBe('600.0000');

    changeSetting($this, $this->finance, 'commission.gold_pct', ['value' => '25', 'reason' => 'New rate'])->assertOk();

    expect(app(PricingContext::class)->breakdown($piece)->commission)->toBe('750.0000');
});

it('changes a flag', function () {
    changeSetting($this, $this->finance, 'manualprice.confirmer_must_differ', ['value' => false, 'reason' => 'Single Finance officer this month'])
        ->assertOk()->assertJsonPath('data.value', false);

    expect(Setting::query()->find('manualprice.confirmer_must_differ')->value_bool)->toBeFalse();
});

it('refuses no reason, a bad value, and an unknown key', function () {
    changeSetting($this, $this->finance, 'vat.pct', ['value' => '15'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
    changeSetting($this, $this->finance, 'vat.pct', ['value' => '101', 'reason' => 'Too high'])->assertUnprocessable()->assertJsonValidationErrors('value');
    changeSetting($this, $this->finance, 'vat.pct', ['value' => '-1', 'reason' => 'Negative'])->assertUnprocessable()->assertJsonValidationErrors('value');
    changeSetting($this, $this->finance, 'vat.pct', ['value' => true, 'reason' => 'Wrong type'])->assertUnprocessable()->assertJsonValidationErrors('value');
    changeSetting($this, $this->finance, 'manualprice.pending_expiry_hours', ['value' => '1.5', 'reason' => 'Not whole'])->assertUnprocessable()->assertJsonValidationErrors('value');
    changeSetting($this, $this->finance, 'price_correction.buy_side', ['value' => '1', 'reason' => 'Gone now'])->assertNotFound();

    expect(SettingHistory::query()->count())->toBe(0);
});

it('keeps rates to the rates permission and operations to the operations one', function () {
    changeSetting($this, $this->coo, 'commission.gold_pct', ['value' => '18', 'reason' => 'COO tries'])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');

    changeSetting($this, $this->coo, 'deadline.seller_reply_hours', ['value' => '36', 'reason' => 'Faster replies'])
        ->assertOk()->assertJsonPath('data.value', '36.0000')->assertJsonPath('data.group', 'operations');

    changeSetting($this, $this->finance, 'deposit.buyer_pct', ['value' => '25', 'reason' => 'Finance tries'])
        ->assertForbidden();
});

it('lists every setting with its group, range and last change, and the history', function () {
    changeSetting($this, $this->coo, 'deadline.seller_reply_hours', ['value' => '36', 'reason' => 'Faster replies'])->assertOk();
    $ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    app('auth')->forgetGuards();

    $list = $this->withToken(staffAccessToken($ops))->getJson('/api/v1/dashboard/settings')->assertOk()->json('data');
    $reply = collect($list)->firstWhere('key', 'deadline.seller_reply_hours');

    expect(count($list))->toBe(26)
        ->and($reply)->toMatchArray(['group' => 'operations', 'type' => 'numeric', 'unit' => 'hours', 'min' => '1', 'max' => null, 'integer' => true])
        ->and($reply['updated_by']['id'])->toBe($this->coo->staff_id);

    $this->getJson('/api/v1/dashboard/settings/history?key=deadline.seller_reply_hours')
        ->assertOk()->assertJsonPath('data.0.old_value', '48.0000')->assertJsonPath('data.0.new_value', '36.0000')
        ->assertJsonPath('data.0.reason', 'Faster replies')->assertJsonPath('meta.total', 1);

    changeSetting($this, $ops, 'deadline.seller_reply_hours', ['value' => '24', 'reason' => 'Ops tries'])->assertForbidden();
});
