<?php

namespace Database\Seeders;

use App\Enums\StaffPermission;
use App\Enums\StaffRole;
use App\Models\Staff;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the fixed dashboard roles (App\Enums\StaffRole) and the permissions
 * the current auth spec needs (App\Enums\StaffPermission) on Spatie's
 * "staff" guard, then syncs each role's permission set to the canonical map.
 *
 * Idempotent: findOrCreate + syncPermissions converge on the same state, so
 * it is safe to run on every deploy. Contains no accounts and no secrets.
 */
class DashboardRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = [];
        foreach (StaffPermission::cases() as $permission) {
            $permissions[$permission->value] = Permission::findOrCreate($permission->value, Staff::GUARD);
        }

        foreach (StaffRole::cases() as $staffRole) {
            $role = Role::findOrCreate($staffRole->value, Staff::GUARD);

            $role->syncPermissions(
                collect(StaffPermission::cases())
                    ->filter(fn (StaffPermission $p) => in_array($staffRole, $p->roles(), true))
                    ->map(fn (StaffPermission $p) => $permissions[$p->value])
                    ->all()
            );
        }

        $registrar->forgetCachedPermissions();
    }
}
