<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 002 FR-014 / SC-002: no sign-out needed, the next request decides.

it('applies a role permission change to the holder on their very next request', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $ceoToken = staffAccessToken(Staff::factory()->role(SeedRole::CEO)->founder()->create());

    $this->bearer($ceoToken)->postJson('/api/v1/dashboard/roles', [
        'name' => 'customer_support', 'display_name' => 'Customer support', 'permissions' => ['customer.view'],
    ])->assertCreated();

    $agent = Staff::factory()->withRole('operations', 'customer_support')->create();
    $agentToken = staffAccessToken($agent);

    $this->bearer($agentToken)->getJson('/api/v1/dashboard/customers')->assertOk();

    $this->bearer($ceoToken)->patchJson('/api/v1/dashboard/roles/customer_support', [
        'permissions' => [], 'reason' => 'Support queue closed',
    ])->assertOk();

    $this->bearer($agentToken)->getJson('/api/v1/dashboard/customers')
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
