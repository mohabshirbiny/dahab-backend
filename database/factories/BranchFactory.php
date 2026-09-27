<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Branch>
 *
 * By default a branch is open Sunday to Thursday, 10:00–18:00, Africa/Cairo.
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name_en' => 'IGI '.$this->faker->unique()->city(),
            'name_ar' => 'فرع',
            'address_en' => $this->faker->streetAddress(),
            'address_ar' => 'القاهرة',
            'timezone' => 'Africa/Cairo',
            'is_enabled' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Branch $branch) {
            if (DB::table('branch_hours')->where('branch_id', $branch->branch_id)->exists()) {
                return;
            }
            foreach ([0, 1, 2, 3, 4] as $dow) {
                DB::table('branch_hours')->insert(['branch_id' => $branch->branch_id, 'dow' => $dow, 'opens_at' => '10:00', 'closes_at' => '18:00']);
            }
        });
    }

    /** No open hours at all (proves the resolver refuses instead of looping). */
    public function closedAllWeek(): static
    {
        return $this->afterCreating(fn (Branch $branch) => DB::table('branch_hours')->where('branch_id', $branch->branch_id)->delete());
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
