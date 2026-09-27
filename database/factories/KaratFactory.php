<?php

namespace Database\Factories;

use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Karat> */
class KaratFactory extends Factory
{
    protected $model = Karat::class;

    /** Every karat has both adjustments; a new one starts at fixed 0 (spec 005, mirrors CreateKaratAction). */
    public function configure(): static
    {
        return $this->afterCreating(fn (Karat $karat) => KaratPriceAdjustment::seedZero($karat->karat_code));
    }

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
