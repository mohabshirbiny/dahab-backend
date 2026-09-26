<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 002 FR-025/FR-026 (Clarification Q1). The seed COO holds roles.manage
// but not customer.view / identity.*, which makes it the natural probe.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create();
});

function expectEscalationDenied($response): void
{
    $response->assertForbidden()->assertJsonPath('code', 'escalation_denied');
}

it('stops a manager from editing a role they hold', function () {
    expectEscalationDenied($this->bearer(staffAccessToken($this->ceo))
        ->patchJson('/api/v1/dashboard/roles/ceo', ['display_name' => 'Boss']));

    expect(StaffRoleModel::findByName('ceo', 'staff')->display_name)->toBe('CEO');
});

it('stops a manager from adding a permission they do not hold', function () {
    expectEscalationDenied($this->bearer(staffAccessToken($this->coo))
        ->patchJson('/api/v1/dashboard/roles/operations', ['permissions' => ['customer.view'], 'reason' => 'Trying to escalate']));

    expect(StaffRoleModel::findByName('operations', 'staff')->permissions)->toBeEmpty();
});

it('stops a manager from removing a permission they do not hold', function () {
    expectEscalationDenied($this->bearer(staffAccessToken($this->coo))
        ->patchJson('/api/v1/dashboard/roles/ceo', [
            'permissions' => ['customer.suspend', 'identity.review', 'identity.view', 'roles.manage', 'staff.view'],
            'reason' => 'Strip customer.view from the CEO',
        ]));

    expect(StaffRoleModel::findByName('ceo', 'staff')->hasPermissionTo('customer.view'))->toBeTrue();
});

it('stops a manager from creating a role with a permission they do not hold', function () {
    expectEscalationDenied($this->bearer(staffAccessToken($this->coo))
        ->postJson('/api/v1/dashboard/roles', ['name' => 'shadow_reviewer', 'display_name' => 'Shadow', 'permissions' => ['identity.review']]));

    expect(StaffRoleModel::query()->where('name', 'shadow_reviewer')->exists())->toBeFalse();
});

it('stops a manager from deleting a role they hold', function () {
    expectEscalationDenied($this->bearer(staffAccessToken($this->coo))
        ->deleteJson('/api/v1/dashboard/roles/coo', ['reason' => 'Removing myself']));
});

it('lets a manager grant a permission they hold', function () {
    $this->bearer(staffAccessToken($this->coo))
        ->patchJson('/api/v1/dashboard/roles/operations', ['permissions' => ['customer.suspend'], 'reason' => 'Ops can suspend now'])
        ->assertOk();
});

it('audits every refusal and leaves no partial change', function () {
    $this->bearer(staffAccessToken($this->coo))
        ->patchJson('/api/v1/dashboard/roles/operations', ['display_name' => 'Ops', 'permissions' => ['customer.view'], 'reason' => 'Trying to escalate']);

    $audit = AuditLog::query()->where('action', 'authz.escalation_denied')->sole();
    expect($audit->actor_staff_id)->toBe($this->coo->staff_id)
        ->and($audit->after_json['offending_permissions'])->toBe(['customer.view'])
        ->and(StaffRoleModel::findByName('operations', 'staff')->display_name)->toBe('Operations')
        ->and(AuditLog::query()->where('action', 'like', 'authz.role.%')->exists())->toBeFalse();
});
