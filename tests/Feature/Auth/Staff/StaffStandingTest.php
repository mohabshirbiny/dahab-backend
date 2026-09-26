<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

// Spec 002 FR-056: a freeze or deactivation stops a live session on its next request.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->staff = Staff::factory()->role(SeedRole::VERIFICATION)->create();
    $this->session = app(IssueTokenFamilyAction::class)->forStaff($this->staff);
});

function freeze(Staff $staff): AccountFreeze
{
    return AccountFreeze::query()->create([
        'frozen_staff_id' => $staff->staff_id,
        'frozen_by' => Staff::factory()->role(SeedRole::CEO)->founder()->create()->staff_id,
        'frozen_at' => now(),
    ]);
}

it('refuses every request from a staff member frozen after sign-in, but still lets them sign out', function () {
    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/customers')->assertOk();

    freeze($this->staff);

    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/customers')
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');
    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/auth/me')
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');

    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')
        ->where('actor_staff_id', $this->staff->staff_id)->exists())->toBeTrue();

    $this->bearer($this->session->accessToken)->postJson('/api/v1/dashboard/auth/logout')->assertNoContent();
});

it('refuses a refresh from a frozen account', function () {
    freeze($this->staff);

    $this->bearer($this->session->refreshToken)->postJson('/api/v1/dashboard/auth/refresh')
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');
});

it('lets an unfrozen account work again on its next request', function () {
    $freeze = freeze($this->staff);
    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/customers')->assertForbidden();

    $freeze->forceFill(['unfrozen_at' => now()])->save();

    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/customers')->assertOk();
});

it('signs out a staff member deactivated after sign-in and revokes the token', function () {
    $this->staff->forceFill(['is_active' => false])->save();

    $this->bearer($this->session->accessToken)->getJson('/api/v1/dashboard/customers')
        ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    expect(PersonalAccessToken::findToken($this->session->accessToken))->toBeNull();
});
