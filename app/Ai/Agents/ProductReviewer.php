<?php

namespace App\Ai\Agents;

use App\Enums\RecommendationDecision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;

class ProductReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert product analyst. Analyze the given product query or image and provide a comprehensive buying recommendation based on market research, reputation, price-to-value analysis, and similar alternatives.

        Return a structured JSON response with:
        - product: name, category, estimated_price_range
        - summary: reputation/reviews summary
        - similar_products: array (1-5) of alternatives with name, reason, and optional price_reference
        - cost_benefit_analysis: detailed price-vs-value analysis
        - recommendation: final decision (buy, buy_if_price_is_good, consider_alternatives, wait, do_not_buy) with reason
        PROMPT;

    public function instructions(): Stringable|string
    {
        return self::SYSTEM_PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => $schema->object(fn ($p) => [
                'name' => $p->string()->required(),
                'category' => $p->string()->nullable(),
                'estimated_price_range' => $p->string()->nullable(),
            ])->required(),
            'summary' => $schema->string()->nullable(),
            'similar_products' => $schema->array()
                ->max(5)
                ->items(
                    $schema->object(fn ($sp) => [
                        'name' => $sp->string()->required(),
                        'reason' => $sp->string()->required(),
                        'price_reference' => $sp->string()->nullable(),
                    ])
                )
                ->required(),
            'cost_benefit_analysis' => $schema->string()->nullable(),
            'recommendation' => $schema->object(fn ($r) => [
                'decision' => $r->string()
                    ->enum(array_map(fn (RecommendationDecision $case) => $case->value, RecommendationDecision::cases()))
                    ->required(),
                'reason' => $r->string()->required(),
            ])->required(),
        ];
    }

    public function analyzeText(string $query): StructuredAgentResponse
    {
        return $this->prompt(
            prompt: $query,
            model: (string) config('worthly.llm.model'),
        );
    }

    public function analyzeImage(string $imagePath, ?string $query = null): StructuredAgentResponse
    {
        $prompt = $query ?? 'Analyze this product image and provide a buying recommendation.';

        return $this->prompt(
            prompt: $prompt,
            attachments: [
                Image::fromStorage($imagePath, disk: 'analysis_images'),
            ],
            model: (string) config('worthly.llm.model'),
        );
    }
}
