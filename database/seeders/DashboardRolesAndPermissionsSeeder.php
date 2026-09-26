<?php

namespace Database\Seeders;

use App\Actions\Authorization\SyncPermissionCatalogueAction;
use Illuminate\Database\Seeder;

/**
 * Syncs the permission catalogue and creates missing seed roles (spec 002).
 * Additive only — it never overwrites role permissions edited from the
 * Dashboard — so it is safe to run on every deploy. Contains no accounts
 * and no secrets.
 */
class DashboardRolesAndPermissionsSeeder extends Seeder
{
    public function run(SyncPermissionCatalogueAction $sync): void
    {
        $sync->handle();
    }
}
