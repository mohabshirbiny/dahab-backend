<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create(['full_name' => 'Aya COO']);
    $this->token = staffAccessToken($this->coo);
});

it('lists staff with their roles, founder flag and permissions, ordered by name, without the system actor', function () {
    Staff::factory()->role(SeedRole::VERIFICATION)->create(['full_name' => 'Zein Verifier']);
    Staff::factory()->role(SeedRole::IGI_BRANCH)->create(['full_name' => 'Badr IGI']);

    $response = $this->bearer($this->token)->getJson('/api/v1/dashboard/staff')->assertOk();

    expect(collect($response->json('data'))->pluck('full_name')->all())->toBe(['Aya COO', 'Badr IGI', 'Zein Verifier'])
        ->and(collect($response->json('data'))->pluck('email'))->not->toContain('system@dahab.internal');

    $response->assertJsonPath('data.0.is_founder', true)
        ->assertJsonPath('data.0.roles', [['name' => 'coo', 'display_name' => 'COO']])
        ->assertJsonPath('data.0.permissions', ['customer.suspend', 'roles.manage', 'staff.view'])
        ->assertJsonPath('data.1.branch_id', 1)
        ->assertJsonPath('meta.per_page', 25);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'full_name', 'email', 'phone', 'is_active', 'is_founder', 'branch_id', 'roles', 'permissions', 'mfa_enrolled']);
});

it('filters by role and paginates', function () {
    Staff::factory()->count(3)->role(SeedRole::VERIFICATION)->create();

    $this->bearer($this->token)->getJson('/api/v1/dashboard/staff?role=verification&per_page=2')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/staff?per_page=51')->assertUnprocessable();
    $this->bearer($this->token)->getJson('/api/v1/dashboard/staff?role=no_such_role')->assertUnprocessable();
});

it('shows one staff member and hides the system actor', function () {
    $this->bearer($this->token)->getJson("/api/v1/dashboard/staff/{$this->coo->staff_id}")
        ->assertOk()->assertJsonPath('data.email', $this->coo->email);

    $system = Staff::query()->where('is_system', true)->sole();
    $this->bearer($this->token)->getJson("/api/v1/dashboard/staff/{$system->staff_id}")->assertNotFound();
    $this->bearer($this->token)->getJson('/api/v1/dashboard/staff/'.Str::uuid())->assertNotFound();
});

it('needs staff.view', function () {
    $verifier = Staff::factory()->role(SeedRole::VERIFICATION)->create();

    $this->bearer(staffAccessToken($verifier))->getJson('/api/v1/dashboard/staff')
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
