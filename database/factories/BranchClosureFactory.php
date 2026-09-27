<?php

namespace Database\Factories;

use App\Models\BranchClosure;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchClosure> */
class BranchClosureFactory extends Factory
{
    protected $model = BranchClosure::class;

    public function definition(): array
    {
        return [
            'branch_id' => null,
            'closure_date' => now()->addDays($this->faker->unique()->numberBetween(10, 300))->toDateString(),
            'reason_en' => 'Public holiday',
            'reason_ar' => 'إجازة رسمية',
        ];
    }
}
