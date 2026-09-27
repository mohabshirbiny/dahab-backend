<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Karat;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 004 US3: karats (FR-020..FR-023). The migration seeds 24/22/21/20/18.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = staffAccessToken(Staff::factory()->role(SeedRole::FINANCE)->create());
    $this->coo = staffAccessToken(Staff::factory()->role(SeedRole::COO)->create());
});

it('lists the seeded karats in display order', function () {
    $response = $this->bearer($this->finance)->getJson('/api/v1/dashboard/karats')->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe([24, 22, 21, 20, 18])
        ->and(collect($response->json('data'))->pluck('is_enabled')->all())->toBe([true, false, true, false, true]);

    $response->assertJsonPath('data.2', ['code' => 21, 'purity' => '0.87500', 'is_enabled' => true, 'sort_order' => 3]);
});

it('lets finance turn a karat on and off, audited', function () {
    $this->bearer($this->finance)->postJson('/api/v1/dashboard/karats/22/toggle', ['enabled' => true])
        ->assertOk()->assertJsonPath('data.code', 22)->assertJsonPath('data.is_enabled', true);

    expect(Karat::query()->find(22)->is_enabled)->toBeTrue();

    $audit = AuditLog::query()->where('action', 'reference.karat.toggled')->sole();
    expect($audit->before_json)->toBe(['is_enabled' => false])
        ->and($audit->after_json['karat_code'])->toBe(22)
        ->and($audit->after_json['is_enabled'])->toBeTrue();
});

it('does not audit a toggle that changes nothing', function () {
    $this->bearer($this->finance)->postJson('/api/v1/dashboard/karats/21/toggle', ['enabled' => true])->assertOk();

    expect(AuditLog::query()->where('action', 'reference.karat.toggled')->exists())->toBeFalse();
});

it('refuses a toggle without the permission, for an unknown karat, or without a flag', function () {
    $ops = staffAccessToken(Staff::factory()->role(SeedRole::OPERATIONS)->create());

    $this->bearer($ops)->postJson('/api/v1/dashboard/karats/22/toggle', ['enabled' => true])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->bearer($this->finance)->postJson('/api/v1/dashboard/karats/9/toggle', ['enabled' => true])->assertNotFound();
    $this->bearer($this->finance)->postJson('/api/v1/dashboard/karats/22/toggle', [])->assertUnprocessable();
});

it('lets the COO add a karat, which starts off and is audited', function () {
    $this->bearer($this->coo)->postJson('/api/v1/dashboard/karats', ['code' => 14, 'purity' => '0.585'])
        ->assertCreated()
        ->assertJsonPath('data', ['code' => 14, 'purity' => '0.58500', 'is_enabled' => false, 'sort_order' => 6]);

    $audit = AuditLog::query()->where('action', 'reference.karat.created')->sole();
    expect($audit->after_json['karat_code'])->toBe(14);
});

it('refuses a duplicate, out-of-range or too precise karat', function (array $body, string $field) {
    $this->bearer($this->coo)->postJson('/api/v1/dashboard/karats', $body)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'duplicate' => [['code' => 21, 'purity' => '0.875'], 'code'],
    'code 0' => [['code' => 0, 'purity' => '0.5'], 'code'],
    'code 25' => [['code' => 25, 'purity' => '0.5'], 'code'],
    'purity 0' => [['code' => 14, 'purity' => '0'], 'purity'],
    'purity over 1' => [['code' => 14, 'purity' => '1.01'], 'purity'],
    'six places' => [['code' => 14, 'purity' => '0.585001'], 'purity'],
]);

it('keeps adding karats to founders and the COO seed', function () {
    $this->bearer($this->finance)->postJson('/api/v1/dashboard/karats', ['code' => 14, 'purity' => '0.585'])->assertForbidden();
});

it('has no route that edits a karat code or purity', function () {
    $this->bearer($this->coo)->patchJson('/api/v1/dashboard/karats/21', ['purity' => '0.9'])->assertNotFound();

    expect(Karat::query()->find(21)->purity_ratio)->toBe('0.87500');
});
