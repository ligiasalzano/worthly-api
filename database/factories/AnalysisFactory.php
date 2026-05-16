<?php

namespace Database\Factories;

use App\Enums\InputType as InputTypeEnum;
use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use App\Models\Analysis;
use App\Models\InputType;
use App\Models\RecommendationDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'input_type_id' => fn () => InputType::firstOrCreate(
                ['slug' => InputTypeEnum::Text->value],
                ['name' => 'Text query', 'is_active' => true],
            )->id,
            'recommendation_decision_id' => fn () => RecommendationDecision::firstOrCreate(
                ['slug' => RecommendationDecisionEnum::BuyIfPriceIsGood->value],
                ['name' => 'Buy if price is good', 'sort_order' => 2, 'is_active' => true],
            )->id,
            'query' => fake()->sentence(),
            'image_path' => null,
            'product_name' => fake()->words(2, true),
            'product_category' => fake()->word(),
            'estimated_price_range' => '$80 - $110',
            'summary' => fake()->paragraph(),
            'cost_benefit_analysis' => fake()->paragraph(),
            'recommendation_reason' => fake()->sentence(),
            'raw_response' => ['fake' => true],
        ];
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'input_type_id' => InputType::firstOrCreate(
                ['slug' => InputTypeEnum::Image->value],
                ['name' => 'Product image', 'is_active' => true],
            )->id,
            'query' => null,
            'image_path' => 'analyses/'.fake()->uuid().'.jpg',
        ]);
    }
}
