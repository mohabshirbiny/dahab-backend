<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 004 US5 (FR-040): a role manager sets or clears a staff member's branch.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create();
    $this->token = staffAccessToken($this->coo);
    $this->igi = Staff::factory()->role(SeedRole::VERIFICATION)->create();
    $this->branch = Branch::factory()->create();
});

it('sets and clears a branch with a reason, audited, and shows it on the profile', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->igi->staff_id}/branch", [
        'branch_id' => $this->branch->branch_id, 'reason' => 'Moved to Nasr City',
    ])->assertOk()->assertJsonPath('data.branch_id', $this->branch->branch_id);

    $this->bearer(staffAccessToken($this->igi))->getJson('/api/v1/dashboard/auth/me')->assertOk();
    expect($this->igi->fresh()->branch_id)->toBe($this->branch->branch_id);

    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->igi->staff_id}/branch", [
        'branch_id' => null, 'reason' => 'Back to head office',
    ])->assertOk()->assertJsonPath('data.branch_id', null);

    $audits = AuditLog::query()->where('action', 'authz.staff.branch_changed')->orderBy('created_at')->orderBy('audit_id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->entity_id)->toBe($this->igi->staff_id)
        ->and($audits[0]->reason)->toBe('Moved to Nasr City')
        ->and($audits[0]->before_json)->toBe(['branch_id' => null])
        ->and($audits[0]->after_json['branch_id'])->toBe($this->branch->branch_id)
        ->and($audits[1]->before_json)->toBe(['branch_id' => $this->branch->branch_id]);
});

it('requires a reason', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->igi->staff_id}/branch", ['branch_id' => $this->branch->branch_id])
        ->assertUnprocessable()->assertJsonPath('code', 'reason_required');

    expect($this->igi->fresh()->branch_id)->toBeNull();
});

it('refuses a disabled or unknown branch', function () {
    $disabled = Branch::factory()->disabled()->create();

    foreach ([$disabled->branch_id, 999] as $branchId) {
        $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->igi->staff_id}/branch", ['branch_id' => $branchId, 'reason' => 'Move them'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }
});

it('refuses a change to your own branch', function () {
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$this->coo->staff_id}/branch", ['branch_id' => $this->branch->branch_id, 'reason' => 'Move myself'])
        ->assertForbidden()->assertJsonPath('code', 'escalation_denied');

    expect($this->coo->fresh()->branch_id)->toBeNull();
});

it('cannot target the system actor and needs roles.manage', function () {
    $system = Staff::query()->where('is_system', true)->sole();
    $this->bearer($this->token)->putJson("/api/v1/dashboard/staff/{$system->staff_id}/branch", ['branch_id' => null, 'reason' => 'Nope nope'])
        ->assertNotFound();

    $ops = staffAccessToken(Staff::factory()->role(SeedRole::OPERATIONS)->create());
    $this->bearer($ops)->putJson("/api/v1/dashboard/staff/{$this->igi->staff_id}/branch", ['branch_id' => null, 'reason' => 'Clear it'])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
