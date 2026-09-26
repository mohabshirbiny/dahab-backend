<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 002 FR-040 and Clarification Q2: MFA follows the founder flag and the
// per-role "requires MFA" flag, never a hard-coded role list.

beforeEach(fn () => $this->seed(DashboardRolesAndPermissionsSeeder::class));

function signIn(Staff $staff)
{
    return test()->postJson('/api/v1/dashboard/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
}

it('forces enrollment for a new role flagged requires_mfa, and stops once the flag is turned off', function () {
    $ceoToken = staffAccessToken(Staff::factory()->role(SeedRole::CEO)->founder()->create());

    $this->bearer($ceoToken)->postJson('/api/v1/dashboard/roles', [
        'name' => 'treasury', 'display_name' => 'Treasury', 'requires_mfa' => true, 'permissions' => [],
    ])->assertCreated();

    $staff = Staff::factory()->withRole('treasury')->withPassword()->create();

    signIn($staff)->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);

    $this->bearer($ceoToken)->patchJson('/api/v1/dashboard/roles/treasury', ['requires_mfa' => false, 'reason' => 'Low-risk role after all'])
        ->assertOk();

    signIn($staff)->assertOk()->assertJsonStructure(['data' => ['staff', 'session']]);
});

it('always forces MFA on founders, whatever their roles say', function () {
    StaffRoleModel::query()->update(['requires_mfa' => false]);

    $founder = Staff::factory()->role(SeedRole::COO)->founder()->withPassword()->create();
    $founderWithoutRoles = Staff::factory()->withRole()->founder()->withPassword()->create();

    signIn($founder)->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);
    signIn($founderWithoutRoles)->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);
});

it('does not force MFA on a non-founder whose roles do not require it', function () {
    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword()->create();

    signIn($staff)->assertOk()->assertJsonMissingPath('data.mfa_enrollment_required')->assertJsonStructure(['data' => ['session']]);
});

it('still challenges a voluntarily enrolled non-founder', function () {
    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword()->withMfa()->create();

    signIn($staff)->assertOk()->assertJsonPath('data.mfa_required', true);
});

it('lets local development switch enforcement off', function () {
    config(['dahab-auth.mfa_enforced' => false]);

    $founder = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword()->create();

    signIn($founder)->assertOk()->assertJsonStructure(['data' => ['session']]);
});
