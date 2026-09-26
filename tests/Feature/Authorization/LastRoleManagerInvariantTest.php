<?php

use App\Actions\Authorization\SetStaffRolesAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 002 FR-023 backstop. Through the API the acting manager always keeps
// roles.manage (FR-026), so the only way to reach "zero managers" is an actor
// who does not count — an inactive one. Actions are called directly.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    // coo would otherwise count as an active manager.
    StaffRoleModel::findByName('coo', 'staff')->revokePermissionTo(StaffPermission::ROLES_MANAGE->value);

    $this->onlyManager = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->inactiveActor = Staff::factory()->role(SeedRole::CEO)->create(['is_active' => false]);
});

function expectLastRoleManager(Closure $change): void
{
    try {
        $change();
        test()->fail('Expected last_role_manager');
    } catch (DomainApiException $e) {
        expect($e->errorCode)->toBe('last_role_manager')->and($e->statusCode)->toBe(409);
    }
}

it('refuses removing the last active manager\'s role', function () {
    expectLastRoleManager(fn () => app(SetStaffRolesAction::class)
        ->handle($this->inactiveActor, $this->onlyManager, [], 'Remove the last manager'));

    expect($this->onlyManager->fresh()->getRoleNames()->all())->toBe(['ceo']);
});

it('refuses stripping roles.manage from the only role that grants it to an active person', function () {
    $super = StaffRoleModel::query()->create(['name' => 'shadow_admin', 'guard_name' => 'staff', 'display_name' => 'Shadow']);
    $super->syncPermissions(array_map(fn ($p) => $p->value, StaffPermission::cases()));
    $this->inactiveActor->syncRoles(['shadow_admin']);

    $all = array_map(fn ($p) => $p->value, StaffPermission::cases());

    expectLastRoleManager(fn () => app(UpdateRoleAction::class)->handle(
        $this->inactiveActor,
        StaffRoleModel::findByName('ceo', 'staff'),
        ['permissions' => array_values(array_diff($all, ['roles.manage'])), 'reason' => 'Remove the last manager'],
    ));

    expect(StaffRoleModel::findByName('ceo', 'staff')->hasPermissionTo('roles.manage'))->toBeTrue();
});
