<?php

use App\Ai\Agents\QueryEnricher;
use App\Exceptions\LlmProviderException;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function buildEnricher(array $structured): QueryEnricher
{
    return new class($structured) extends QueryEnricher
    {
        public function __construct(private array $structured) {}

        protected function callModel(string $rawQuery): StructuredAgentResponse
        {
            return new StructuredAgentResponse(
                invocationId: (string) Str::uuid7(),
                structured: $this->structured,
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

it('preserves all four axes without deduplication', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $subQueries = [
        'iPhone 15 preço Brasil 2026',
        'iPhone 15 review Wirecutter RTINGS',
        'iPhone 15 reddit experience problems',
        'iPhone 15 vs Samsung S24 Pixel 8',
    ];

    $fake = buildEnricher([
        'raw_query' => 'iPhone 15',
        'product_name' => 'iPhone 15',
        'brand' => 'Apple',
        'category' => 'smartphone',
        'region' => 'BR',
        'use_case' => null,
        'budget_hint' => null,
        'intent' => 'buy_decision',
        'sub_queries' => $subQueries,
        'hyde_passages' => [],
    ]);

    $result = $fake->enrich('iPhone 15');

    expect($result->subQueries)->toBe($subQueries);
    expect($result->subQueries)->toHaveCount(4);
});

it('raises LlmProviderException when the model returns fewer than the configured minimum sub-queries', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $fake = buildEnricher([
        'raw_query' => 'iPhone 15',
        'product_name' => 'iPhone 15',
        'brand' => 'Apple',
        'category' => 'smartphone',
        'region' => 'BR',
        'use_case' => null,
        'budget_hint' => null,
        'intent' => 'buy_decision',
        'sub_queries' => ['only', 'two'],
        'hyde_passages' => [],
    ]);

    expect(fn () => $fake->enrich('iPhone 15'))
        ->toThrow(LlmProviderException::class);
});
