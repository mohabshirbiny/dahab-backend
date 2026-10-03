<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Payout accounts (spec 013). Fixtures only for reads and schema tests: the
 * feature tests reach every state through the Actions, which also write the
 * history and the pause.
 *
 * @extends Factory<PayoutAccount>
 */
class PayoutAccountFactory extends Factory
{
    protected $model = PayoutAccount::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->verified(),
            'account_name' => fake()->name(),
            'bank_name' => 'CIB',
            'account_number_or_iban' => (string) fake()->numerify('1000########'),
            'state' => 'pending_review',
        ];
    }

    /** Verified and in use, as if a staff member had checked it. */
    public function verified(): static
    {
        return $this->state(fn () => [
            'state' => 'active',
            'is_in_use' => true,
            'name_checked_by' => Staff::query()->where('is_system', true)->value('staff_id'),
            'name_checked_at' => now(),
            'activated_at' => now(),
        ]);
    }
}
