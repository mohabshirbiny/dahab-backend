<?php

namespace Database\Factories;

use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\Staff;
use App\Models\StaffMfa;
use App\Models\StaffPassword;
use App\Models\StaffRoleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use WeakMap;

/**
 * Staff members hold roles only through Spatie (spec 002). The factory takes
 * a transient `roles` attribute (default: operations), strips it before the
 * insert, and assigns those roles after creating. A seed role that does not
 * exist yet is created with its seed display name and "requires MFA" flag;
 * its permissions come from DashboardRolesAndPermissionsSeeder — seed it in
 * tests that need them.
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /** @var WeakMap<Staff, list<string>>|null */
    private static ?WeakMap $pendingRoles = null;

    public function definition(): array
    {
        return [
            'roles' => [SeedRole::OPERATIONS->value],
            'full_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '+2011'.$this->faker->unique()->numerify('########'),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        self::$pendingRoles ??= new WeakMap;

        return $this
            ->afterMaking(function (Staff $staff) {
                self::$pendingRoles[$staff] = (array) $staff->getAttribute('roles');
                $staff->offsetUnset('roles');
            })
            ->afterCreating(function (Staff $staff) {
                foreach (self::$pendingRoles[$staff] ?? [] as $name) {
                    $staff->assignRole(self::ensureRole($name));
                }
                unset(self::$pendingRoles[$staff]);
            });
    }

    /** Hold exactly these roles (none when called with no arguments). */
    public function withRole(string ...$names): static
    {
        $state = ['roles' => array_values($names)];

        if (in_array(SeedRole::IGI_BRANCH->value, $names, true)) {
            $state['branch_id'] = 1;
        }

        return $this->state(fn () => $state);
    }

    /** Shorthand for a single seed role. */
    public function role(SeedRole $role): static
    {
        return $this->withRole($role->value);
    }

    /** Founder flag; never mass-assignable, so it is forced after creation. */
    public function founder(): static
    {
        return $this->afterCreating(fn (Staff $staff) => $staff->forceFill(['is_founder' => true])->save());
    }

    /** The single system actor already exists (migration); use only in constraint tests. */
    public function system(): static
    {
        return $this->withRole()->afterCreating(fn (Staff $staff) => $staff->forceFill(['is_system' => true])->save());
    }

    public static function ensureRole(string $name): StaffRoleModel
    {
        $seed = SeedRole::tryFrom($name);

        return StaffRoleModel::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => Staff::GUARD],
            [
                'display_name' => $seed?->displayName() ?? ucwords(str_replace('_', ' ', $name)),
                'requires_mfa' => $seed?->seedRequiresMfa() ?? false,
            ],
        );
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
                'frozen_by' => Staff::factory()->role(SeedRole::CEO)->founder()->create()->staff_id,
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
