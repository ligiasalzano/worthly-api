<?php

use App\Ai\Agents\EvidenceVerifier;
use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\AnalysisPipeline;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use App\Enums\Intent;
use App\Enums\RecommendationDecision;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use App\Models\AnalysisSource;
use App\Models\HarnessRun;
use App\Models\SimilarProduct;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);

    config()->set('cache.default', 'array');
    config()->set('worthly.harness.verifier.enabled', false);
    config()->set('worthly.harness.retrievers.shopping.enabled', true);
    config()->set('worthly.harness.retrievers.reviews.enabled', true);
    config()->set('worthly.harness.retrievers.general.enabled', true);
    Cache::store('array')->flush();
});

function pipelineE2eEnricher(?string $productName, Intent $intent): QueryEnricher
{
    return new class($productName, $intent) extends QueryEnricher
    {
        public function __construct(private ?string $productName, private Intent $intent) {}

        public function enrich(string $rawQuery): EnrichedQuery
        {
            return new EnrichedQuery(
                rawQuery: $rawQuery,
                productName: $this->productName,
                brand: 'Apple',
                category: 'smartphone',
                region: 'BR',
                useCase: null,
                budgetHint: null,
                intent: $this->intent,
                subQueries: ['iPhone 15 review'],
                hydePassages: [],
            );
        }
    };
}

function pipelineE2eReviewer(string $decision): ProductReviewer
{
    return new class($decision) extends ProductReviewer
    {
        public function __construct(private string $decision) {}

        public function recommend(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
        {
            return new StructuredAgentResponse(
                invocationId: (string) Str::uuid7(),
                structured: [
                    'product' => [
                        'name' => $query->productName ?? 'Unknown',
                        'category' => 'smartphone',
                        'estimated_price_range' => 'R$ 5000 - R$ 6000',
                    ],
                    'summary' => 'iPhone 15 has strong reviews.',
                    'similar_products' => [
                        [
                            'name' => 'Samsung Galaxy S24',
                            'reason' => 'comparable flagship',
                            'price_reference' => 'R$ 4500',
                        ],
                    ],
                    'cost_benefit_analysis' => 'Fair value for the iPhone price.',
                    'recommendation' => [
                        'decision' => $this->decision,
                        'reason' => 'Wirecutter recommends the iPhone 15 with great battery life.',
                    ],
                    'confidence' => 'medium',
                    'sources_used' => [
                        ['field' => 'summary', 'evidence_ids' => ['S1']],
                        ['field' => 'recommendation', 'evidence_ids' => ['S1', 'S2']],
                    ],
                ],
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

function fakeRetrievalHttp(): void
{
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response([
            'results' => [
                [
                    'url' => 'https://www.rtings.com/iphone-15',
                    'title' => 'iPhone 15 review',
                    'content' => 'iPhone 15 has great battery life and camera.',
                    'score' => 0.95,
                ],
                [
                    'url' => 'https://www.wirecutter.com/iphone-15',
                    'title' => 'iPhone 15 Wirecutter pick',
                    'content' => 'Wirecutter recommends the iPhone 15 for most buyers.',
                    'score' => 0.92,
                ],
            ],
        ], 200),
        SearchApiClient::BASE_URL.'*' => Http::response([
            'shopping_results' => [
                [
                    'product_link' => 'https://shop.example/iphone-15',
                    'title' => 'iPhone 15 128GB',
                    'price' => 'R$ 5499',
                ],
            ],
        ], 200),
        'https://api.mercadolibre.com/sites/MLB/search*' => Http::response([
            'results' => [
                [
                    'permalink' => 'https://ml.example/iphone-15',
                    'title' => 'iPhone 15 BR',
                    'price' => 5300,
                ],
            ],
        ], 200),
        'https://api.cohere.com/v2/rerank' => Http::response([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.85],
                ['index' => 2, 'relevance_score' => 0.75],
                ['index' => 3, 'relevance_score' => 0.65],
            ],
        ], 200),
        'https://api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
        ], 200),
    ]);
}

function bindE2eAgents(?string $productName, Intent $intent, string $decision): void
{
    app()->instance(QueryEnricher::class, pipelineE2eEnricher($productName, $intent));
    app()->instance(ProductIdentifier::class, app(ProductIdentifier::class));
    app()->instance(ProductReviewer::class, pipelineE2eReviewer($decision));
    app()->instance(EvidenceVerifier::class, app(EvidenceVerifier::class));
}

it('persists Analysis, SimilarProduct, AnalysisSource, and HarnessRun rows from end-to-end pipeline', function () {
    fakeRetrievalHttp();
    bindE2eAgents('iPhone 15', Intent::BuyDecision, 'buy');

    $user = User::factory()->create();

    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 worth buying?');

    expect($analysis)->toBeInstanceOf(Analysis::class);
    expect($analysis->product_name)->toBe('iPhone 15');

    expect(SimilarProduct::query()->where('analysis_id', $analysis->id)->count())->toBeGreaterThan(0);
    expect(AnalysisSource::query()->where('analysis_id', $analysis->id)->count())->toBeGreaterThan(0);
    expect(HarnessRun::query()->where('analysis_id', $analysis->id)->count())->toBe(1);
});

it('exposes sources[], confidence and degraded keys in the AnalysisResource shape', function () {
    fakeRetrievalHttp();
    bindE2eAgents('iPhone 15', Intent::BuyDecision, 'buy');

    $user = User::factory()->create();

    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 worth buying?');
    $analysis->load(['similarProducts', 'inputType', 'recommendationDecision', 'sources']);

    $resource = AnalysisResource::make($analysis)->resolve();

    expect($resource)->toHaveKey('sources');
    expect($resource)->toHaveKey('confidence');
    expect($resource)->toHaveKey('degraded');
    expect($resource['sources'])->toBeArray();
    expect($resource['confidence'])->toBeIn(['high', 'medium', 'low']);
    expect($resource['degraded'])->toBeBool();
});

it('resolves the recommendation_decision enum case correctly when the reviewer returns buy', function () {
    fakeRetrievalHttp();
    bindE2eAgents('iPhone 15', Intent::BuyDecision, 'buy');

    $user = User::factory()->create();
    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 worth buying?');

    expect($analysis->recommendationDecisionEnum())->toBe(RecommendationDecision::Buy);
});

it('falls back to insufficient_evidence when the enricher cannot identify the product', function () {
    fakeRetrievalHttp();
    bindE2eAgents(null, Intent::Unknown, 'buy');

    $user = User::factory()->create();
    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'asdfghjkl');

    expect($analysis->recommendationDecisionEnum())->toBe(RecommendationDecision::InsufficientEvidence);
    expect($analysis->degraded)->toBeTrue();
});

arch()
    ->expect('App\Ai\Harness\Retrieval')
    ->not->toUse('Laravel\Ai');

arch()
    ->expect('App\Ai\Agents')
    ->not->toUse('Illuminate\Http\Client');
