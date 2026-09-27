<?php

namespace Database\Factories;

use App\Models\Karat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Karat> */
class KaratFactory extends Factory
{
    protected $model = Karat::class;

    public function definition(): array
    {
        return [
            'karat_code' => $this->faker->unique()->numberBetween(1, 17),
            'purity_ratio' => '0.50000',
            'is_enabled' => false,
            'sort_order' => 9,
        ];
    }
}
