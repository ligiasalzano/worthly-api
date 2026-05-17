<?php

namespace Tests\Support;

use App\Ai\Harness\AnalysisPipeline;
use App\Models\Analysis;
use App\Models\InputType;
use App\Models\RecommendationDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StructuredAgentResponse;

class FakeAnalysisPipeline extends AnalysisPipeline
{
    public function __construct(private FakeProductReviewer $reviewer) {}

    public function analyzeText(User $user, string $query): Analysis
    {
        return $this->persist($user, 'text', $query, null, $this->reviewer->analyzeText($query));
    }

    public function analyzeImage(User $user, string $imagePath): Analysis
    {
        return $this->persist($user, 'image', null, $imagePath, $this->reviewer->analyzeImage($imagePath));
    }

    private function persist(
        User $user,
        string $inputTypeSlug,
        ?string $query,
        ?string $imagePath,
        StructuredAgentResponse $response,
    ): Analysis {
        return DB::transaction(function () use ($user, $inputTypeSlug, $query, $imagePath, $response) {
            $data = $response->structured;

            $inputType = InputType::firstWhere('slug', $inputTypeSlug);
            $decision = RecommendationDecision::firstWhere('slug', $data['recommendation']['decision']);

            $analysis = Analysis::create([
                'user_id' => $user->id,
                'input_type_id' => $inputType->id,
                'recommendation_decision_id' => $decision->id,
                'query' => $query,
                'image_path' => $imagePath,
                'product_name' => $data['product']['name'],
                'product_category' => $data['product']['category'] ?? null,
                'estimated_price_range' => $data['product']['estimated_price_range'] ?? null,
                'summary' => $data['summary'] ?? null,
                'cost_benefit_analysis' => $data['cost_benefit_analysis'] ?? null,
                'recommendation_reason' => $data['recommendation']['reason'],
                'raw_response' => $data,
            ]);

            foreach ($data['similar_products'] ?? [] as $index => $similar) {
                $analysis->similarProducts()->create([
                    'name' => $similar['name'],
                    'reason' => $similar['reason'],
                    'price_reference' => $similar['price_reference'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $analysis;
        });
    }
}
