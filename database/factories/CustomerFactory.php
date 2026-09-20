<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\CustomerPassword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Default state is `active` so existing tests that were written against the
     * pre-lifecycle flags keep passing without needing a `->verified()` call.
     * Use `->pendingVerification()` for a freshly submitted customer.
     */
    public function definition(): array
    {
        return [
            'display_ref' => str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'phone' => '+2010'.$this->faker->unique()->numerify('########'),
            'email' => $this->faker->optional()->safeEmail(),
            'preferred_lang' => 'ar',
            'status' => CustomerStatus::ACTIVE->value,
            'is_verified' => true,
            'is_suspended' => false,
        ];
    }

    public function marketMaker(): static
    {
        return $this->state(fn () => ['customer_type' => CustomerType::MARKET_MAKER->value]);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn () => [
            'status' => CustomerStatus::PENDING_VERIFICATION->value,
            'is_verified' => false,
            'is_suspended' => false,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => CustomerStatus::REJECTED->value,
            'is_verified' => false,
            'is_suspended' => false,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => CustomerStatus::ACTIVE->value,
            'is_verified' => true,
            'is_suspended' => false,
        ]);
    }

    public function suspended(SuspendedReason $reason = SuspendedReason::POLICY_VIOLATION, ?string $byStaffId = null): static
    {
        return $this->state(fn () => [
            'status' => CustomerStatus::SUSPENDED->value,
            'is_verified' => true,
            'is_suspended' => true,
            'suspended_reason' => $reason,
            'suspended_by' => $byStaffId,
            'suspended_at' => now(),
        ]);
    }

    public function withPassword(string $plain = 'correct-horse-battery'): static
    {
        return $this->afterCreating(function (Customer $customer) use ($plain) {
            CustomerPassword::query()->create([
                'customer_id' => $customer->customer_id,
                'password_hash' => Hash::make($plain),
                'password_changed_at' => now(),
            ]);
        });
    }
}
