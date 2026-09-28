<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/AuditLogTestHelpers.php';

uses(RefreshDatabase::class);

// Spec 006 US2 / FR-005 / SC-003: own-actions viewers never see anyone else's entries.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->financeToken = staffAccessToken($this->finance);

    $this->mine = auditRow('pricing.setting.changed', ['actor_staff_id' => $this->finance->staff_id]);
    $this->theirs = auditRow('pricing.manual_price.confirmed', ['actor_staff_id' => $this->ceo->staff_id]);
    $this->customerRow = auditRow('auth.customer.registered', ['actor_customer_id' => Customer::factory()->create()->customer_id]);
});

it('shows an own-actions viewer only their own entries, count included', function () {
    $this->bearer($this->financeToken)->getJson('/api/v1/dashboard/audit-log')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->mine);
});

it('gives them nothing when they ask for someone else', function () {
    $this->bearer($this->financeToken)->getJson('/api/v1/dashboard/audit-log?actor='.$this->ceo->staff_id)
        ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

    $this->bearer($this->financeToken)->getJson("/api/v1/dashboard/audit-log/{$this->theirs}")->assertNotFound();
    $this->bearer($this->financeToken)->getJson("/api/v1/dashboard/audit-log/{$this->customerRow}")->assertNotFound();
    $this->bearer($this->financeToken)->getJson("/api/v1/dashboard/audit-log/{$this->mine}")->assertOk();
});

it('shows everything, customers included, to a view_all holder', function () {
    $this->bearer(staffAccessToken($this->ceo))->getJson('/api/v1/dashboard/audit-log')->assertJsonPath('meta.total', 3);
});

it('lets a role given view_all from the Dashboard see everything', function () {
    StaffRoleModel::findByName('finance', 'staff')->givePermissionTo('audit.view_all');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->bearer($this->financeToken)->getJson('/api/v1/dashboard/audit-log')->assertJsonPath('meta.total', 3);
});

it('lets in a holder of either code and refuses a holder of neither, audited', function () {
    $igi = Staff::factory()->role(SeedRole::IGI_BRANCH)->create();

    $this->bearer(staffAccessToken($igi))->getJson('/api/v1/dashboard/audit-log')
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');

    $denied = AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $igi->staff_id)->sole();
    expect($denied->after_json['permission'])->toBe('audit.view_all|audit.view_own');

    // Finance holds only audit.view_own and passes the same route.
    $this->bearer($this->financeToken)->getJson('/api/v1/dashboard/audit-log/categories')->assertOk();
});
