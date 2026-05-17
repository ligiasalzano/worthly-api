<?php

use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Enums\Intent;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeQueryEnricherFake(array $structured): QueryEnricher
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

it('maps a fully populated structured response onto EnrichedQuery', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $fake = makeQueryEnricherFake([
        'raw_query' => 'vale a pena comprar iPhone 15?',
        'product_name' => 'iPhone 15',
        'brand' => 'Apple',
        'category' => 'smartphone',
        'region' => 'BR',
        'use_case' => 'daily driver',
        'budget_hint' => 'R$ 5000',
        'intent' => 'buy_decision',
        'sub_queries' => [
            'iPhone 15 preço Brasil 2026',
            'iPhone 15 review Wirecutter',
            'iPhone 15 reddit experience problems',
            'iPhone 15 vs Samsung S24',
        ],
        'hyde_passages' => ['The iPhone 15 is Apple\'s 2023 flagship...'],
    ]);

    $result = $fake->enrich('vale a pena comprar iPhone 15?');

    expect($result)->toBeInstanceOf(EnrichedQuery::class);
    expect($result->rawQuery)->toBe('vale a pena comprar iPhone 15?');
    expect($result->productName)->toBe('iPhone 15');
    expect($result->brand)->toBe('Apple');
    expect($result->category)->toBe('smartphone');
    expect($result->region)->toBe('BR');
    expect($result->useCase)->toBe('daily driver');
    expect($result->budgetHint)->toBe('R$ 5000');
    expect($result->intent)->toBe(Intent::BuyDecision);
    expect($result->subQueries)->toHaveCount(4);
    expect($result->hydePassages)->toBe(['The iPhone 15 is Apple\'s 2023 flagship...']);
});

it('preserves an unknown intent without silent correction', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $fake = makeQueryEnricherFake([
        'raw_query' => 'something cool',
        'product_name' => null,
        'brand' => null,
        'category' => null,
        'region' => null,
        'use_case' => null,
        'budget_hint' => null,
        'intent' => 'unknown',
        'sub_queries' => ['a', 'b', 'c', 'd'],
        'hyde_passages' => [],
    ]);

    $result = $fake->enrich('something cool');

    expect($result->intent)->toBe(Intent::Unknown);
    expect($result->productName)->toBeNull();
    expect($result->brand)->toBeNull();
    expect($result->budgetHint)->toBeNull();
});

it('coerces null optional fields and empty strings into null', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $fake = makeQueryEnricherFake([
        'raw_query' => 'something',
        'product_name' => 'Widget',
        'brand' => '   ',
        'category' => null,
        'region' => 'BR',
        'use_case' => '',
        'budget_hint' => null,
        'intent' => 'spec_lookup',
        'sub_queries' => ['1', '2', '3', '4'],
        'hyde_passages' => [],
    ]);

    $result = $fake->enrich('something');

    expect($result->brand)->toBeNull();
    expect($result->category)->toBeNull();
    expect($result->useCase)->toBeNull();
    expect($result->budgetHint)->toBeNull();
    expect($result->intent)->toBe(Intent::SpecLookup);
});
