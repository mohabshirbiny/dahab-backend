<?php

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\AccountFreeze;
use App\Models\Staff;
use App\Models\StaffMfa;
use App\Models\StaffPassword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'role' => StaffRole::OPERATIONS,
            'full_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '+2011'.$this->faker->unique()->numerify('########'),
            'is_active' => true,
        ];
    }

    /**
     * Mirror the `role` column onto Spatie so the new staff member carries
     * that role's permissions. Permissions come from
     * DashboardRolesAndPermissionsSeeder — seed it in tests that need them.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Staff $staff) {
            $staff->assignRole(Role::findOrCreate($staff->role->value, Staff::GUARD));
        });
    }

    public function role(StaffRole $role): static
    {
        return $this->state(fn () => ['role' => $role, 'branch_id' => $role === StaffRole::IGI_BRANCH ? 1 : null]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** An open `account_freeze`, raised by another (freshly created) staff member. */
    public function frozen(): static
    {
        return $this->afterCreating(function (Staff $staff) {
            AccountFreeze::query()->create([
                'frozen_staff_id' => $staff->staff_id,
                'frozen_by' => Staff::factory()->role(StaffRole::CEO)->create()->staff_id,
                'frozen_at' => now(),
            ]);
        });
    }

    /**
     * TOTP already enrolled. $recoveryCodes are stored hashed, like the real
     * enrollment does; pass the plaintext codes a test intends to redeem.
     *
     * @param  list<string>  $recoveryCodes
     */
    public function withMfa(string $secret = 'JBSWY3DPEHPK3PXP', array $recoveryCodes = []): static
    {
        return $this->afterCreating(function (Staff $staff) use ($secret, $recoveryCodes) {
            StaffMfa::query()->create([
                'staff_id' => $staff->staff_id,
                'mfa_secret_encrypted' => $secret,
                'enrolled_at' => now(),
                'recovery_codes_hash' => array_map(fn (string $code) => Hash::make($code), $recoveryCodes),
            ]);
        });
    }

    public function withPassword(string $plain = 'correct-horse-battery'): static
    {
        return $this->afterCreating(function (Staff $staff) use ($plain) {
            StaffPassword::query()->create([
                'staff_id' => $staff->staff_id,
                'password_hash' => Hash::make($plain),
                'password_changed_at' => now(),
            ]);
        });
    }
}
