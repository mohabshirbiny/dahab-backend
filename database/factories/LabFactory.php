<?php

namespace Database\Factories;

use App\Models\Lab;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lab> */
class LabFactory extends Factory
{
    protected $model = Lab::class;

    public function definition(): array
    {
        return [
            'name_en' => 'Lab '.$this->faker->unique()->city(),
            'name_ar' => 'معمل',
            'certificate_own_cost_egp' => '100',
            'certificate_other_cost_egp' => '700',
            'certificate_none_cost_egp' => '1500',
            'gold_inspection_cost_egp' => '0',
            'is_enabled' => true,
        ];
    }
}
