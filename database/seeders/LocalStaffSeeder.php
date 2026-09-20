<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Staff;
use App\Models\StaffPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One dashboard account per role for local development and tests
 * (spec 001 quickstart). Refuses to run anywhere else, so no seeded
 * credential can ever exist in staging or production.
 */
class LocalStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalStaffSeeder skipped: only runs in local/testing.');

            return;
        }

        $this->call(DashboardRolesAndPermissionsSeeder::class);

        $password = (string) config('dahab-auth.local_seed_password');

        foreach (StaffRole::cases() as $role) {
            $staff = Staff::query()->updateOrCreate(
                ['email' => "{$role->value}@dahab.test"],
                [
                    'role' => $role,
                    'full_name' => 'Local '.strtoupper($role->value),
                    'is_active' => true,
                    // staff.igi_has_branch: an IGI account is always branch-bound.
                    'branch_id' => $role === StaffRole::IGI_BRANCH ? 1 : null,
                ],
            );

            $staff->syncRoles([$role->value]);

            // firstOrCreate: never clobber a password a developer changed.
            StaffPassword::query()->firstOrCreate(
                ['staff_id' => $staff->staff_id],
                ['password_hash' => Hash::make($password), 'password_changed_at' => now()],
            );
        }
    }
}
