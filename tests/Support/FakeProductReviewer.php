<?php

namespace Tests\Support;

use App\Ai\Agents\ProductReviewer;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

class FakeProductReviewer extends ProductReviewer
{
    public function __construct(
        private ?StructuredAgentResponse $response = null,
    ) {}

    public function analyzeText(string $query): StructuredAgentResponse
    {
        return $this->response ?? $this->defaultResponse();
    }

    public function analyzeImage(string $imagePath, ?string $query = null): StructuredAgentResponse
    {
        return $this->response ?? $this->defaultResponse();
    }

    private function defaultResponse(): StructuredAgentResponse
    {
        $structured = [
            'product' => [
                'name' => 'Test Product',
                'category' => 'Electronics',
                'estimated_price_range' => '$80 - $110',
            ],
            'summary' => 'A highly-rated product with excellent reviews.',
            'similar_products' => [
                [
                    'name' => 'Alternative Product A',
                    'reason' => 'Similar specs at a lower price.',
                    'price_reference' => '$60 - $80',
                ],
            ],
            'cost_benefit_analysis' => 'Good value for the price. Worth considering.',
            'recommendation' => [
                'decision' => 'buy_if_price_is_good',
                'reason' => 'Excellent product, but negotiate on price.',
            ],
        ];

        return new StructuredAgentResponse(
            invocationId: (string) Str::uuid7(),
            structured: $structured,
            text: 'Fake response',
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
                cacheWriteInputTokens: 0,
                cacheReadInputTokens: 0,
                reasoningTokens: 0,
            ),
            meta: new Meta,
        );
    }
}
