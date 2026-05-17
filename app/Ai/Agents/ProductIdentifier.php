<?php

namespace App\Ai\Agents;

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Enums\Intent;
use App\Exceptions\LlmProviderException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;
use Throwable;

class ProductIdentifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are a vision-only product identifier for a buying-recommendation
        assistant.

        Given a single product image, extract:
        - All visible text (brand name, model number, packaging copy) into
          `extracted_text`. Keep it as-is, including line breaks if useful.
        - The canonical product name (`product_name`) when you can identify it
          with confidence. If you are not confident, return null — do not guess.
        - Brand, category, and region clues when present. Otherwise null.
        - The user's intent. When you can identify the product, set intent to
          `buy_decision`. When you cannot identify the product, set intent to
          `unknown`.
        - Sub-queries covering the four research axes (price, professional
          review, opinion, alternatives) for downstream retrieval. Always return
          at least 3 sub-queries when `product_name` is non-null. When
          `product_name` is null, return an empty list.

        Never invent products that are not clearly visible in the image.
        PROMPT;

    public function instructions(): Stringable|string
    {
        return self::SYSTEM_PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'extracted_text' => $schema->string()->nullable()->required(),
            'product_name' => $schema->string()->nullable()->required(),
            'brand' => $schema->string()->nullable()->required(),
            'category' => $schema->string()->nullable()->required(),
            'region' => $schema->string()->nullable()->required(),
            'use_case' => $schema->string()->nullable()->required(),
            'budget_hint' => $schema->string()->nullable()->required(),
            'intent' => $schema->string()
                ->enum(array_map(fn (Intent $case) => $case->value, Intent::cases()))
                ->required(),
            'sub_queries' => $schema->array()
                ->max(5)
                ->items($schema->string())
                ->required(),
        ];
    }

    public function identify(string $imagePath, string $disk = 'analysis_images'): EnrichedQuery
    {
        try {
            $response = $this->callModel($imagePath, $disk);
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'The product identifier failed to respond.',
                previous: $e,
            );
        }

        $data = $response->structured;

        $productName = $this->nullableString($data['product_name'] ?? null);
        $extractedText = $this->nullableString($data['extracted_text'] ?? null);
        $rawIntent = $this->resolveIntent($data['intent'] ?? null);
        $intent = $productName !== null && $rawIntent === Intent::Unknown
            ? Intent::BuyDecision
            : ($productName === null ? Intent::Unknown : $rawIntent);

        return new EnrichedQuery(
            rawQuery: $extractedText ?? '',
            productName: $productName,
            brand: $this->nullableString($data['brand'] ?? null),
            category: $this->nullableString($data['category'] ?? null),
            region: $this->nullableString($data['region'] ?? null),
            useCase: $this->nullableString($data['use_case'] ?? null),
            budgetHint: $this->nullableString($data['budget_hint'] ?? null),
            intent: $intent,
            subQueries: $this->normalizeStringList($data['sub_queries'] ?? []),
            hydePassages: [],
        );
    }

    protected function callModel(string $imagePath, string $disk): StructuredAgentResponse
    {
        return $this->prompt(
            prompt: 'Identify the product visible in this image.',
            attachments: [
                Image::fromStorage($imagePath, disk: $disk),
            ],
            model: (string) config('worthly.harness.cheap_model'),
        );
    }

    private function resolveIntent(mixed $value): Intent
    {
        if ($value instanceof Intent) {
            return $value;
        }

        if (! is_string($value)) {
            return Intent::Unknown;
        }

        return Intent::tryFrom($value) ?? Intent::Unknown;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values($normalized);
    }
}
