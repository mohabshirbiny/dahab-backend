<?php

namespace Database\Factories;

use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Freezes (spec 021). Default: an open freeze of a fresh founder (COO) by another (CEO).
 * Pass `frozen_staff_id` / `frozen_by` to freeze existing members.
 */
class AccountFreezeFactory extends Factory
{
    protected $model = AccountFreeze::class;

    public function definition(): array
    {
        return [
            'frozen_staff_id' => fn () => Staff::factory()->role(SeedRole::COO)->founder()->create()->staff_id,
            'frozen_by' => fn () => Staff::factory()->role(SeedRole::CEO)->founder()->create()->staff_id,
            'frozen_at' => now(),
            'reason' => 'Lost phone, possible compromise',
        ];
    }

    /** Slot 1 given: by the frozen founder, or by `$by` as the second approver. */
    public function firstConfirmed(string $kind = AccountFreeze::KIND_FROZEN_FOUNDER, ?Staff $by = null): static
    {
        // Closures run after the earlier attributes are resolved (frozen_staff_id first).
        return $this->state(fn () => [
            'unfreeze_confirm_1' => fn (array $attributes) => $kind === AccountFreeze::KIND_FROZEN_FOUNDER
                ? $attributes['frozen_staff_id']
                : ($by?->staff_id ?? Staff::factory()->create()->staff_id),
            'unfreeze_confirm_1_at' => now(),
            'unfreeze_confirm_1_kind' => $kind,
        ]);
    }

    /** Both confirmations given: slot 1 by the frozen founder, slot 2 by the freezer. */
    public function unfrozen(): static
    {
        return $this->firstConfirmed()->state(fn () => [
            'unfreeze_confirm_2' => fn (array $attributes) => $attributes['frozen_by'],
            'unfreeze_confirm_2_at' => now(),
            'unfrozen_at' => now(),
        ]);
    }
}
