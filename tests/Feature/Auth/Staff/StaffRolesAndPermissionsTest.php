<?php

use App\Enums\StaffPermission;
use App\Enums\StaffRole;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

it('runs staff on the dedicated staff guard', function () {
    $staff = Staff::factory()->create();

    expect(Guard::getNames($staff)->all())->toBe(['staff'])
        ->and(Role::findByName('ceo', 'staff')->guard_name)->toBe('staff');
});

it('seeds exactly the six fixed roles and only the spec permissions', function () {
    expect(Role::where('guard_name', 'staff')->pluck('name')->sort()->values()->all())
        ->toBe(collect(StaffRole::cases())->map->value->sort()->values()->all())
        ->and(Permission::where('guard_name', 'staff')->pluck('name')->all())
        ->toBe(collect(StaffPermission::cases())->map->value->all());
});

it('is idempotent: reseeding leaves the same rows', function () {
    $before = [Role::count(), Permission::count(), DB::table('role_has_permissions')->count()];

    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    expect([Role::count(), Permission::count(), DB::table('role_has_permissions')->count()])->toBe($before);
});

it('gives customer.suspend to the founders and to no one else', function (StaffRole $role, bool $allowed) {
    $staff = Staff::factory()->role($role)->create();

    expect($staff->hasRole($role->value))->toBeTrue()
        ->and($staff->hasPermissionTo('customer.suspend'))->toBe($allowed)
        ->and($staff->can('customer.suspend'))->toBe($allowed);
})->with([
    'ceo' => [StaffRole::CEO, true],
    'coo' => [StaffRole::COO, true],
    'finance' => [StaffRole::FINANCE, false],
    'operations' => [StaffRole::OPERATIONS, false],
    'verification' => [StaffRole::VERIFICATION, false],
    'igi_branch' => [StaffRole::IGI_BRANCH, false],
]);

it('gives identity.view and identity.review to the CEO and Verification roles and to no one else', function (StaffRole $role, bool $allowed) {
    $staff = Staff::factory()->role($role)->create();

    foreach (['identity.view', 'identity.review'] as $permission) {
        expect($staff->hasPermissionTo($permission))->toBe($allowed)
            ->and($staff->can($permission))->toBe($allowed);
    }
})->with([
    'ceo' => [StaffRole::CEO, true],
    'verification' => [StaffRole::VERIFICATION, true],
    'coo' => [StaffRole::COO, false],
    'finance' => [StaffRole::FINANCE, false],
    'operations' => [StaffRole::OPERATIONS, false],
    'igi_branch' => [StaffRole::IGI_BRANCH, false],
]);

it('keeps the identity permissions on the staff guard only', function () {
    foreach (['identity.view', 'identity.review'] as $name) {
        expect(Permission::where('name', $name)->pluck('guard_name')->all())->toBe(['staff']);
    }
});

it('assigns, syncs and removes roles through Spatie', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    expect($staff->hasRole('operations'))->toBeTrue()->and($staff->hasRole('ceo'))->toBeFalse();

    $staff->assignRole('coo');
    expect($staff->fresh()->hasRole('coo'))->toBeTrue()
        ->and($staff->fresh()->can('customer.suspend'))->toBeTrue();

    $staff->removeRole('coo');
    expect($staff->fresh()->can('customer.suspend'))->toBeFalse();

    $staff->syncRoles(['finance']);
    expect($staff->fresh()->getRoleNames()->all())->toBe(['finance']);
});

it('grants and revokes direct permissions through Spatie', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    expect($staff->can('customer.suspend'))->toBeFalse();

    $staff->givePermissionTo('customer.suspend');
    expect($staff->fresh()->hasPermissionTo('customer.suspend'))->toBeTrue()
        ->and($staff->fresh()->can('customer.suspend'))->toBeTrue();

    $staff->syncPermissions([]);
    expect($staff->fresh()->hasPermissionTo('customer.suspend'))->toBeFalse();
});

it('fails closed on a permission that was never seeded', function () {
    $staff = Staff::factory()->role(StaffRole::CEO)->create();

    expect($staff->can('wallet.view'))->toBeFalse();
});
