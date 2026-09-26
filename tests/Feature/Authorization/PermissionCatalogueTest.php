<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DashboardRolesAndPermissionsSeeder::class));

it('lists the whole permission catalogue for a role manager', function () {
    $ceo = Staff::factory()->role(SeedRole::CEO)->create();

    $response = $this->bearer(staffAccessToken($ceo))->getJson('/api/v1/dashboard/permissions')->assertOk();

    expect(collect($response->json('data'))->pluck('code')->sort()->values()->all())
        ->toBe(collect(StaffPermission::cases())->map->value->sort()->values()->all());

    $response->assertJsonPath('data.0', fn (array $p) => array_keys($p) === ['code', 'label', 'group', 'branch_scoped']);
    expect(collect($response->json('data'))->firstWhere('code', 'roles.manage'))
        ->toBe(['code' => 'roles.manage', 'label' => StaffPermission::ROLES_MANAGE->label(), 'group' => 'Access control', 'branch_scoped' => false]);
});

it('refuses staff without roles.manage and audits the denial', function () {
    $ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    $this->bearer(staffAccessToken($ops))->getJson('/api/v1/dashboard/permissions')
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');

    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $ops->staff_id)->exists())->toBeTrue();
});

it('refuses anonymous callers and customer tokens', function () {
    $this->getJson('/api/v1/dashboard/permissions')->assertUnauthorized();

    $customer = Customer::factory()->verified()->create();
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->bearer($token)->getJson('/api/v1/dashboard/permissions')->assertUnauthorized();
});
