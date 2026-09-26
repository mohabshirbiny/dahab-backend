<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->token = staffAccessToken($this->ceo);
    $this->ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();
});

it('replaces a staff member\'s roles, audits it, and applies on their next request', function () {
    $opsToken = staffAccessToken($this->ops);
    $this->bearer($opsToken)->getJson('/api/v1/dashboard/identity-documents')->assertForbidden();

    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", [
        'roles' => ['operations', 'verification'],
        'reason' => 'Covering the verification queue',
    ])->assertOk()
        ->assertJsonPath('data.roles', [['name' => 'operations', 'display_name' => 'Operations'], ['name' => 'verification', 'display_name' => 'Verification']])
        ->assertJsonPath('data.permissions', ['customer.view', 'identity.review', 'identity.view']);

    $audit = AuditLog::query()->where('action', 'authz.staff.roles_changed')->sole();
    expect($audit->entity_id)->toBe($this->ops->staff_id)
        ->and($audit->reason)->toBe('Covering the verification queue')
        ->and($audit->before_json)->toBe(['roles' => ['operations']])
        ->and($audit->after_json['added'])->toBe(['verification'])
        ->and($audit->after_json['removed'])->toBe([]);

    $this->bearer($opsToken)->getJson('/api/v1/dashboard/identity-documents')->assertOk();
    $this->bearer($opsToken)->getJson('/api/v1/dashboard/auth/me')->assertJsonPath('data.permissions', ['customer.view', 'identity.review', 'identity.view']);
});

it('allows an empty role list', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", ['roles' => [], 'reason' => 'On leave'])
        ->assertOk()->assertJsonPath('data.roles', [])->assertJsonPath('data.permissions', []);
});

it('requires a reason and known role names', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", ['roles' => ['verification']])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", ['roles' => ['wizard'], 'reason' => 'Promote'])
        ->assertUnprocessable()->assertJsonValidationErrors('roles.0');

    expect($this->ops->fresh()->getRoleNames()->all())->toBe(['operations']);
});

it('refuses changes to your own roles', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ceo->staff_id}/roles", ['roles' => [], 'reason' => 'Stepping down'])
        ->assertForbidden()->assertJsonPath('code', 'escalation_denied');

    expect($this->ceo->fresh()->getRoleNames()->all())->toBe(['ceo']);
});

it('refuses assigning a role that carries a permission the manager lacks', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    $this->bearer(staffAccessToken($coo))->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", [
        'roles' => ['operations', 'verification'], 'reason' => 'Promote',
    ])->assertForbidden()->assertJsonPath('code', 'escalation_denied');

    expect(AuditLog::query()->where('action', 'authz.escalation_denied')->sole()->after_json['offending_permissions'])
        ->toEqualCanonicalizing(['customer.view', 'identity.review', 'identity.view']);
});

it('never changes founder status, whatever the body says', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->ops->staff_id}/roles", [
        'roles' => ['coo'], 'reason' => 'Promote to COO role', 'is_founder' => true,
    ])->assertOk()->assertJsonPath('data.is_founder', false);

    expect($this->ops->fresh()->is_founder)->toBeFalse();
});

it('cannot assign roles to the system actor', function () {
    $system = Staff::query()->where('is_system', true)->sole();

    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$system->staff_id}/roles", ['roles' => ['ceo'], 'reason' => 'Nope'])
        ->assertNotFound();
});
