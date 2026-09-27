<?php

namespace Database\Seeders;

use App\Enums\SeedRole;
use App\Models\Branch;
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
        // The IGI account is bound to a sample branch (spec 004).
        $this->call(LocalReferenceSeeder::class);

        $password = (string) config('dahab-auth.local_seed_password');

        foreach (SeedRole::cases() as $role) {
            $staff = Staff::query()->updateOrCreate(
                ['email' => "{$role->value}@dahab.test"],
                [
                    'full_name' => 'Local '.strtoupper($role->value),
                    'is_active' => true,
                    // The local IGI account is bound to branch 1.
                    // The first sample branch from LocalReferenceSeeder (spec 004).
                    'branch_id' => $role === SeedRole::IGI_BRANCH ? Branch::query()->orderBy('branch_id')->value('branch_id') : null,
                ],
            );

            // Founder status is never mass-assignable (spec 002 FR-043).
            $staff->forceFill(['is_founder' => in_array($role, [SeedRole::CEO, SeedRole::COO], true)])->save();

            $staff->syncRoles([$role->value]);

            // firstOrCreate: never clobber a password a developer changed.
            StaffPassword::query()->firstOrCreate(
                ['staff_id' => $staff->staff_id],
                ['password_hash' => Hash::make($password), 'password_changed_at' => now()],
            );
        }
    }
}
