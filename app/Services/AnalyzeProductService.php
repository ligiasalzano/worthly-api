<?php

namespace App\Services;

use App\Ai\Agents\ProductReviewer;
use App\Exceptions\LlmProviderException;
use App\Models\Analysis;
use App\Models\InputType;
use App\Models\RecommendationDecision;
use App\Models\User;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

class AnalyzeProductService
{
    public function __construct(private ProductReviewer $agent) {}

    public function analyzeText(User $user, string $query): Analysis
    {
        try {
            $response = $this->agent->analyzeText($query);
        } catch (FailoverableException $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_failed',
                message: 'The LLM provider failed to complete the analysis.',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'An unexpected error occurred while analyzing the product.',
                previous: $e,
            );
        }

        return $this->persistAnalysis(
            user: $user,
            inputTypeSlug: 'text',
            query: $query,
            imagePath: null,
            response: $response,
        );
    }

    public function analyzeImage(User $user, string $imagePath): Analysis
    {
        try {
            $response = $this->agent->analyzeImage($imagePath);
        } catch (FailoverableException $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_failed',
                message: 'The LLM provider failed to complete the analysis.',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'An unexpected error occurred while analyzing the product.',
                previous: $e,
            );
        }

        return $this->persistAnalysis(
            user: $user,
            inputTypeSlug: 'image',
            query: null,
            imagePath: $imagePath,
            response: $response,
        );
    }

    private function persistAnalysis(
        User $user,
        string $inputTypeSlug,
        ?string $query,
        ?string $imagePath,
        $response,
    ): Analysis {
        return \DB::transaction(function () use ($user, $inputTypeSlug, $query, $imagePath, $response) {
            $responseData = $response->structured;

            $inputType = InputType::firstWhere('slug', $inputTypeSlug);
            $recommendationDecision = RecommendationDecision::firstWhere(
                'slug',
                $responseData['recommendation']['decision'],
            );

            $analysis = Analysis::create([
                'user_id' => $user->id,
                'input_type_id' => $inputType->id,
                'recommendation_decision_id' => $recommendationDecision->id,
                'query' => $query,
                'image_path' => $imagePath,
                'product_name' => $responseData['product']['name'],
                'product_category' => $responseData['product']['category'] ?? null,
                'estimated_price_range' => $responseData['product']['estimated_price_range'] ?? null,
                'summary' => $responseData['summary'] ?? null,
                'cost_benefit_analysis' => $responseData['cost_benefit_analysis'] ?? null,
                'recommendation_reason' => $responseData['recommendation']['reason'],
                'raw_response' => $responseData,
            ]);

            foreach ($responseData['similar_products'] as $index => $similarProduct) {
                $analysis->similarProducts()->create([
                    'name' => $similarProduct['name'],
                    'reason' => $similarProduct['reason'],
                    'price_reference' => $similarProduct['price_reference'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $analysis;
        });
    }
}
