<?php

namespace App\Actions\Authorization;

use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the `permissions` table in step with the StaffPermission catalogue
 * and creates the seed roles on a fresh install (spec 002 FR-070, research
 * R2). Safe to run on every deploy because it is additive only:
 *
 * - a code new to the catalogue is created and given to `ceo` plus its
 *   `seedRoles()` — once, at the moment it first appears;
 * - a code no longer in the catalogue is deleted (and so leaves every role);
 * - a seed role is created with its seed permissions only if it is missing.
 *
 * It never re-syncs an existing role, so permissions edited from the
 * Dashboard survive deploys.
 */
final class SyncPermissionCatalogueAction
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $this->registrar->forgetCachedPermissions();

            $catalogue = array_map(fn (StaffPermission $p) => $p->value, StaffPermission::cases());

            Permission::query()
                ->where('guard_name', Staff::GUARD)
                ->whereNotIn('name', $catalogue)
                ->delete();

            // 1. Codes new to the catalogue: create them and remember which.
            $existing = Permission::query()->where('guard_name', Staff::GUARD)->pluck('name')->all();
            $new = [];
            foreach (StaffPermission::cases() as $code) {
                if (! in_array($code->value, $existing, true)) {
                    Permission::query()->create(['name' => $code->value, 'guard_name' => Staff::GUARD]);
                    $new[] = $code;
                }
            }

            // 2. Missing seed roles: create with their full seed permission set.
            $created = [];
            foreach (SeedRole::cases() as $seed) {
                $role = StaffRoleModel::query()->firstOrCreate(
                    ['name' => $seed->value, 'guard_name' => Staff::GUARD],
                    ['display_name' => $seed->displayName(), 'requires_mfa' => $seed->seedRequiresMfa()],
                );

                if ($role->wasRecentlyCreated) {
                    $role->givePermissionTo($this->seedPermissionsFor($seed));
                    $created[] = $seed->value;
                }
            }

            // 3. Roles that already existed receive only the codes that just appeared.
            foreach ($new as $code) {
                $holders = StaffRoleModel::query()
                    ->where('guard_name', Staff::GUARD)
                    ->whereIn('name', [SeedRole::CEO->value, ...$code->seedRoles()])
                    ->whereNotIn('name', $created)
                    ->get();

                foreach ($holders as $role) {
                    $role->givePermissionTo($code->value);
                }
            }

            $this->registrar->forgetCachedPermissions();
        });
    }

    /** @return list<string> */
    private function seedPermissionsFor(SeedRole $seed): array
    {
        return array_values(array_map(
            fn (StaffPermission $p) => $p->value,
            array_filter(
                StaffPermission::cases(),
                fn (StaffPermission $p) => $seed === SeedRole::CEO || in_array($seed->value, $p->seedRoles(), true),
            ),
        ));
    }
}
