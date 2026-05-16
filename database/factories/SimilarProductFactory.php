<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\SimilarProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimilarProduct>
 */
class SimilarProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'name' => fake()->words(2, true),
            'reason' => fake()->sentence(),
            'price_reference' => '$50 - $90',
            'sort_order' => 0,
        ];
    }
}
