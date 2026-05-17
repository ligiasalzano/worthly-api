<?php

namespace Database\Seeders;

use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use App\Models\RecommendationDecision;
use Illuminate\Database\Seeder;

class RecommendationDecisionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            RecommendationDecisionEnum::Buy->value => ['name' => 'Buy', 'sort_order' => 1, 'description' => 'Confident buy recommendation'],
            RecommendationDecisionEnum::BuyIfPriceIsGood->value => ['name' => 'Buy if price is good', 'sort_order' => 2, 'description' => 'Buy provided the price is fair'],
            RecommendationDecisionEnum::ConsiderAlternatives->value => ['name' => 'Consider alternatives', 'sort_order' => 3, 'description' => 'Look at alternative products before buying'],
            RecommendationDecisionEnum::Wait->value => ['name' => 'Wait', 'sort_order' => 4, 'description' => 'Wait before buying — better timing or revision is likely'],
            RecommendationDecisionEnum::DoNotBuy->value => ['name' => 'Do not buy', 'sort_order' => 5, 'description' => 'Avoid this purchase'],
            RecommendationDecisionEnum::InsufficientEvidence->value => ['name' => 'Insufficient evidence', 'sort_order' => 6, 'description' => 'Not enough evidence to make a recommendation'],
        ];

        foreach ($rows as $slug => $attributes) {
            RecommendationDecision::updateOrCreate(['slug' => $slug], $attributes + ['is_active' => true]);
        }
    }
}
