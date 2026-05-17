<?php

namespace App\Ai\Agents;

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Enums\Intent;
use App\Exceptions\LlmProviderException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;
use Throwable;

class QueryEnricher implements Agent, HasStructuredOutput
{
    use Promptable;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are a product-search query analyst for a buying-recommendation assistant.

        Given a free-text user query in any language, extract a structured intent
        description and decompose the query into search sub-queries for downstream
        retrieval.

        You MUST:
        - Identify the canonical product name, brand, category, and region when present.
          Use null for fields you cannot infer with confidence.
        - Classify the user's intent as exactly one of: buy_decision, compare,
          spec_lookup, unknown. Use `unknown` rather than guessing.
        - Produce sub-queries covering all four research axes whenever the intent
          allows: (1) price/availability, (2) professional reviews,
          (3) user opinion / forum discussion, (4) alternatives and competitors.
          Do not collapse the axes. Do not deduplicate aggressively — each axis
          should be represented even if the wording overlaps.
        - Return at least the configured minimum number of sub-queries.
        - `hyde_passages` is optional; produce 0–3 short imagined ideal answers
          when they would help retrieval.

        Never invent products that the query does not reasonably suggest. When in
        doubt, set product_name to null and intent to `unknown`.
        PROMPT;

    public function instructions(): Stringable|string
    {
        return self::SYSTEM_PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $subQueryCount = (int) config('worthly.harness.query_enricher.sub_query_count', 4);
        $minSubQueries = max(3, min(5, $subQueryCount));

        return [
            'raw_query' => $schema->string()->required(),
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
                ->min($minSubQueries)
                ->max(5)
                ->items($schema->string())
                ->required(),
            'hyde_passages' => $schema->array()
                ->max(3)
                ->items($schema->string())
                ->required(),
        ];
    }

    public function enrich(string $rawQuery): EnrichedQuery
    {
        try {
            $response = $this->callModel($rawQuery);
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'The query enricher failed to respond.',
                previous: $e,
            );
        }

        $data = $response->structured;

        $subQueries = $this->normalizeStringList($data['sub_queries'] ?? []);
        $hydePassages = $this->normalizeStringList($data['hyde_passages'] ?? []);
        $minSubQueries = (int) config('worthly.harness.query_enricher.sub_query_count', 4);

        if (count($subQueries) < $minSubQueries) {
            throw new LlmProviderException(
                errorCode: 'query_enricher_axes_underfilled',
                message: sprintf(
                    'QueryEnricher returned %d sub-queries; configured minimum is %d.',
                    count($subQueries),
                    $minSubQueries,
                ),
            );
        }

        $intent = $this->resolveIntent($data['intent'] ?? null);

        return new EnrichedQuery(
            rawQuery: (string) ($data['raw_query'] ?? $rawQuery),
            productName: $this->nullableString($data['product_name'] ?? null),
            brand: $this->nullableString($data['brand'] ?? null),
            category: $this->nullableString($data['category'] ?? null),
            region: $this->nullableString($data['region'] ?? null),
            useCase: $this->nullableString($data['use_case'] ?? null),
            budgetHint: $this->nullableString($data['budget_hint'] ?? null),
            intent: $intent,
            subQueries: $subQueries,
            hydePassages: $hydePassages,
        );
    }

    protected function callModel(string $rawQuery): StructuredAgentResponse
    {
        return $this->prompt(
            prompt: $rawQuery,
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
