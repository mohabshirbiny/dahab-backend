<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->token = staffAccessToken($this->ceo);
});

function newRole(array $overrides = []): array
{
    return array_merge([
        'name' => 'customer_support',
        'display_name' => 'Customer support',
        'description' => 'Answers customer questions',
        'permissions' => ['customer.view'],
    ], $overrides);
}

it('creates a role and audits it', function () {
    $this->bearer($this->token)->postJson('/api/v1/dashboard/roles', newRole())
        ->assertCreated()
        ->assertExactJsonStructure(['data' => ['name', 'display_name', 'description', 'requires_mfa', 'permissions', 'staff_count', 'created_at', 'updated_at']])
        ->assertJsonPath('data.name', 'customer_support')
        ->assertJsonPath('data.requires_mfa', false)
        ->assertJsonPath('data.permissions', ['customer.view'])
        ->assertJsonPath('data.staff_count', 0);

    $audit = AuditLog::query()->where('action', 'authz.role.created')->sole();
    expect($audit->actor_staff_id)->toBe($this->ceo->staff_id)
        ->and($audit->after_json['permissions'])->toBe(['customer.view'])
        ->and($audit->after_json['role'])->toBe('customer_support');
});

it('validates the role body', function (array $body, string $field) {
    StaffRoleModel::query()->create(['name' => 'taken_name', 'guard_name' => 'staff', 'display_name' => 'Taken']);

    $this->bearer($this->token)->postJson('/api/v1/dashboard/roles', newRole($body))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field);
})->with([
    'duplicate name' => [['name' => 'taken_name'], 'name'],
    'bad name' => [['name' => 'Customer Support'], 'name'],
    'too short name' => [['name' => 'cs'], 'name'],
    'unknown permission' => [['permissions' => ['wallet.teleport']], 'permissions.0'],
    'long display name' => [['display_name' => str_repeat('x', 101)], 'display_name'],
    'long description' => [['description' => str_repeat('x', 501)], 'description'],
]);

it('shows one role and 404s an unknown one', function () {
    $this->bearer($this->token)->getJson('/api/v1/dashboard/roles/verification')
        ->assertOk()->assertJsonPath('data.permissions', ['customer.view', 'identity.review', 'identity.view']);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/roles/nobody_has_this')
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('lists roles by display name with holder counts that ignore the system actor', function () {
    Staff::factory()->count(2)->role(SeedRole::VERIFICATION)->create();

    $data = $this->bearer($this->token)->getJson('/api/v1/dashboard/roles')->assertOk()->json('data');

    expect(collect($data)->pluck('display_name')->all())->toBe(collect($data)->pluck('display_name')->sort()->values()->all())
        ->and(collect($data)->firstWhere('name', 'verification')['staff_count'])->toBe(2)
        ->and(collect($data)->firstWhere('name', 'ceo')['staff_count'])->toBe(1);
});

it('edits labels without a reason', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/operations', ['display_name' => 'Ops', 'description' => 'Runs the floor'])
        ->assertOk()->assertJsonPath('data.display_name', 'Ops')->assertJsonPath('data.description', 'Runs the floor');

    expect(AuditLog::query()->where('action', 'authz.role.updated')->sole()->after_json)
        ->toMatchArray(['display_name' => 'Ops', 'description' => 'Runs the floor']);
});

it('requires a reason to change permissions or the MFA flag', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/operations', ['permissions' => ['customer.view']])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/operations', ['requires_mfa' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    expect(StaffRoleModel::findByName('operations', 'staff')->permissions)->toBeEmpty();
});

it('changes permissions with a reason and audits what was added and removed', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/verification', [
        'permissions' => ['customer.view', 'customer.suspend'],
        'reason' => 'Verification now handles suspensions',
    ])->assertOk()->assertJsonPath('data.permissions', ['customer.suspend', 'customer.view']);

    $audit = AuditLog::query()->where('action', 'authz.role.permissions_changed')->sole();
    expect($audit->reason)->toBe('Verification now handles suspensions')
        ->and($audit->before_json['permissions'])->toBe(['customer.view', 'identity.review', 'identity.view'])
        ->and($audit->after_json['added'])->toBe(['customer.suspend'])
        ->and($audit->after_json['removed'])->toBe(['identity.review', 'identity.view']);
});

it('changes the MFA flag with a reason and audits it', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/operations', ['requires_mfa' => true, 'reason' => 'Handles sensitive data'])
        ->assertOk()->assertJsonPath('data.requires_mfa', true);

    $audit = AuditLog::query()->where('action', 'authz.role.mfa_changed')->sole();
    expect($audit->before_json)->toBe(['requires_mfa' => false])
        ->and($audit->after_json['requires_mfa'])->toBeTrue();
});

it('never changes the machine name', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/roles/operations', ['name' => 'renamed', 'display_name' => 'Ops'])
        ->assertOk()->assertJsonPath('data.name', 'operations');

    expect(StaffRoleModel::query()->where('name', 'renamed')->exists())->toBeFalse();
});

it('refuses to delete a role that staff hold, and deletes an unheld one with a reason', function () {
    Staff::factory()->role(SeedRole::OPERATIONS)->create();

    $this->bearer($this->token)->deleteJson('/api/v1/dashboard/roles/operations', ['reason' => 'Not needed'])
        ->assertStatus(409)->assertJsonPath('code', 'role_in_use');

    $this->bearer($this->token)->deleteJson('/api/v1/dashboard/roles/finance')
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    $this->bearer($this->token)->deleteJson('/api/v1/dashboard/roles/finance', ['reason' => 'Finance merged into CEO office'])
        ->assertNoContent();

    expect(StaffRoleModel::query()->where('name', 'finance')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'authz.role.deleted')->sole()->before_json['role'])->toBe('finance');
});
