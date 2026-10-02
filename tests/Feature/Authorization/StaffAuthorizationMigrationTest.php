<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Database\Seeders\LocalStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Spec 002 SC-003: roles become data with no change in who can do what.

it('drops the fixed staff.role column and enum type', function () {
    expect(Schema::hasColumn('staff', 'role'))->toBeFalse()
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_type WHERE typname = 'staff_role'")->n)->toBe(0)
        ->and(Schema::hasColumns('staff', ['is_founder', 'is_system']))->toBeTrue()
        ->and(Schema::hasColumns('roles', ['display_name', 'description', 'requires_mfa']))->toBeTrue();
});

it('creates exactly one system actor', function () {
    expect(Staff::query()->where('is_system', true)->count())->toBe(1);
});

it('seeds today\'s access map plus the new access-control permissions for both founders', function () {
    $this->seed([DashboardRolesAndPermissionsSeeder::class, LocalStaffSeeder::class]);

    $expected = [
        'ceo' => ['audit.view_all', 'audit.view_own', 'branches.manage', 'buy_request.view', 'customer.suspend', 'customer.view', 'gold_price.confirm', 'gold_price.enter', 'identity.review', 'identity.view', 'inspection.enter', 'karats.create', 'karats.toggle', 'listing.request_changes', 'listing.review', 'listing.takedown', 'order.cancel', 'order.change_branch', 'order.extend_deadline', 'order.handover', 'order.price_adjust', 'order.receive', 'order.view', 'pricing.rates.manage', 'pricing.view', 'reference.view', 'roles.manage', 'settings.manage', 'staff.view', 'topup.accounts.manage', 'topup.match', 'wallet.view'],
        'coo' => ['audit.view_own', 'branches.manage', 'buy_request.view', 'customer.suspend', 'karats.create', 'listing.request_changes', 'listing.review', 'listing.takedown', 'order.cancel', 'order.change_branch', 'order.extend_deadline', 'order.price_adjust', 'order.receive', 'order.view', 'pricing.view', 'reference.view', 'roles.manage', 'settings.manage', 'staff.view'],
        'finance' => ['audit.view_own', 'customer.view', 'gold_price.confirm', 'gold_price.enter', 'karats.toggle', 'order.view', 'pricing.rates.manage', 'pricing.view', 'reference.view', 'topup.accounts.manage', 'topup.match', 'wallet.view'],
        'operations' => ['audit.view_own', 'branches.manage', 'buy_request.view', 'listing.request_changes', 'listing.review', 'listing.takedown', 'order.cancel', 'order.change_branch', 'order.extend_deadline', 'order.price_adjust', 'order.receive', 'order.view', 'pricing.view', 'reference.view'],
        'verification' => ['audit.view_own', 'customer.view', 'identity.review', 'identity.view'],
        'igi_branch' => ['inspection.enter', 'order.handover', 'order.receive'],
    ];

    foreach ($expected as $role => $permissions) {
        $staff = Staff::where('email', "{$role}@dahab.test")->sole();

        expect($staff->effectivePermissionCodes())->toBe($permissions, $role)
            ->and($staff->is_founder)->toBe(in_array($role, ['ceo', 'coo'], true), $role);
    }

    expect(StaffRoleModel::query()->where('requires_mfa', true)->orderBy('name')->pluck('name')->all())
        ->toBe(['ceo', 'coo', 'finance']);
});

it('never overwrites role permissions edited from the Dashboard when reseeding', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    $verification = StaffRoleModel::findByName(SeedRole::VERIFICATION->value, 'staff');
    $verification->revokePermissionTo('identity.review');

    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    expect($verification->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['audit.view_own', 'customer.view', 'identity.view']);
});

it('gives a code new to the catalogue to ceo and its seed roles, and removes retired codes', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    // Simulate "staff.view is new in this release" and "a retired code is still in the table".
    DB::table('permissions')->where('name', 'staff.view')->delete();
    DB::table('permissions')->insert(['name' => 'retired.code', 'guard_name' => 'staff', 'created_at' => now(), 'updated_at' => now()]);
    StaffRoleModel::findByName('operations', 'staff')->givePermissionTo('retired.code');

    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    expect(StaffRoleModel::findByName('ceo', 'staff')->hasPermissionTo('staff.view'))->toBeTrue()
        ->and(StaffRoleModel::findByName('coo', 'staff')->hasPermissionTo('staff.view'))->toBeTrue()
        ->and(StaffRoleModel::findByName('verification', 'staff')->permissions->pluck('name'))->not->toContain('staff.view')
        ->and(DB::table('permissions')->where('name', 'retired.code')->exists())->toBeFalse();
});
