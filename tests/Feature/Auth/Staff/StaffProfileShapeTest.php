<?php

use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 002 research R13: nothing the Dashboard reads today is removed.

beforeEach(fn () => $this->seed(DashboardRolesAndPermissionsSeeder::class));

it('exposes roles, roles_detail, is_founder and the deprecated role field on /me', function () {
    $staff = Staff::factory()->withRole('verification', 'operations')->founder()->create();

    $this->bearer(staffAccessToken($staff))->getJson('/api/v1/dashboard/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', 'operations')
        ->assertJsonPath('data.roles', ['operations', 'verification'])
        ->assertJsonPath('data.roles_detail', [
            ['name' => 'operations', 'display_name' => 'Operations'],
            ['name' => 'verification', 'display_name' => 'Verification'],
        ])
        ->assertJsonPath('data.is_founder', true);
});

it('returns a null role for a staff member with no roles', function () {
    $staff = Staff::factory()->withRole()->create();

    $this->bearer(staffAccessToken($staff))->getJson('/api/v1/dashboard/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', null)
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', []);
});

it('returns the same shape in the sign-in response', function () {
    $staff = Staff::factory()->withRole('operations')->withPassword('correct-horse-battery')->create();

    $this->postJson('/api/v1/dashboard/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('data.staff.roles', ['operations'])
        ->assertJsonPath('data.staff.roles_detail.0.display_name', 'Operations')
        ->assertJsonPath('data.staff.is_founder', false);
});
