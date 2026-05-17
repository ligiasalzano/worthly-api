<?php

use App\Ai\Agents\EvidenceVerifier;
use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\AnalysisPipeline;
use App\Ai\Harness\Contracts\Reranker;
use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Rerank\NullReranker;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use App\Enums\Intent;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
    config()->set('worthly.harness.cache.response_ttl', 86400);
    config()->set('worthly.harness.verifier.enabled', false);
    Cache::store('array')->flush();
});

function responseCacheEnricher(): QueryEnricher
{
    return new class extends QueryEnricher
    {
        public function enrich(string $rawQuery): EnrichedQuery
        {
            return new EnrichedQuery(
                rawQuery: $rawQuery,
                productName: 'iPhone 15',
                brand: 'Apple',
                category: 'smartphone',
                region: 'BR',
                useCase: null,
                budgetHint: null,
                intent: Intent::BuyDecision,
                subQueries: ['iPhone 15 review'],
                hydePassages: [],
            );
        }
    };
}

function responseCacheIdentifier(): ProductIdentifier
{
    return new class extends ProductIdentifier
    {
        public function identify(string $imagePath, string $disk = 'analysis_images'): EnrichedQuery
        {
            return new EnrichedQuery(
                rawQuery: 'iPhone 15 box',
                productName: 'iPhone 15',
                brand: 'Apple',
                category: 'smartphone',
                region: 'BR',
                useCase: null,
                budgetHint: null,
                intent: Intent::BuyDecision,
                subQueries: ['iPhone 15 specs'],
                hydePassages: [],
            );
        }
    };
}

function responseCacheRetriever(): Retriever
{
    return new class implements Retriever
    {
        public function name(): string
        {
            return 'reviews';
        }

        public function isEligible(EnrichedQuery $query): bool
        {
            return true;
        }

        public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array
        {
            return [
                new EvidenceItem('reviews', 'https://example.test/a', 'iPhone 15 review', 'iPhone battery great', null, 0.9, 0.8),
                new EvidenceItem('reviews', 'https://example.test/b', 'iPhone 15 specs', 'iPhone camera details', null, 0.9, 0.7),
            ];
        }
    };
}

class ResponseCacheReviewerSpy extends ProductReviewer
{
    public int $calls = 0;

    public function recommend(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
    {
        $this->calls++;

        return new StructuredAgentResponse(
            invocationId: (string) Str::uuid7(),
            structured: [
                'product' => [
                    'name' => 'iPhone 15',
                    'category' => 'smartphone',
                    'estimated_price_range' => 'R$ 5000 - R$ 6000',
                ],
                'summary' => 'Great phone with strong battery.',
                'similar_products' => [],
                'cost_benefit_analysis' => 'Fair pricing.',
                'recommendation' => [
                    'decision' => 'buy',
                    'reason' => 'Excellent overall.',
                ],
                'confidence' => 'medium',
            ],
            text: 'fake',
            usage: new Usage(0, 0, 0, 0, 0),
            meta: new Meta,
        );
    }
}

function bindResponseCachePipeline(ResponseCacheReviewerSpy $reviewer): void
{
    app()->instance(QueryEnricher::class, responseCacheEnricher());
    app()->instance(ProductIdentifier::class, responseCacheIdentifier());
    app()->instance(ProductReviewer::class, $reviewer);
    app()->instance(EvidenceVerifier::class, app(EvidenceVerifier::class));

    app()->bind(RetrievalRouter::class, fn () => new RetrievalRouter([responseCacheRetriever()]));
    app()->bind(Reranker::class, NullReranker::class);
}

it('caches the LLM response by enriched query + evidence ids for identical text inputs', function () {
    $user = User::factory()->create();

    $reviewer = new ResponseCacheReviewerSpy;
    bindResponseCachePipeline($reviewer);

    $pipeline = app(AnalysisPipeline::class);

    $pipeline->analyzeText($user, 'iPhone 15 review');
    $pipeline->analyzeText($user, 'iPhone 15 review');

    expect($reviewer->calls)->toBe(1);
});

it('bypasses the response cache for image input — both calls hit the LLM', function () {
    Storage::fake('analysis_images');
    Storage::disk('analysis_images')->put('analyses/test.jpg', 'fake-bytes');

    $user = User::factory()->create();

    $reviewer = new ResponseCacheReviewerSpy;
    bindResponseCachePipeline($reviewer);

    $pipeline = app(AnalysisPipeline::class);

    $pipeline->analyzeImage($user, 'analyses/test.jpg');
    $pipeline->analyzeImage($user, 'analyses/test.jpg');

    expect($reviewer->calls)->toBe(2);
});

it('uses the configured response TTL of 24h', function () {
    expect((int) config('worthly.harness.cache.response_ttl'))->toBe(86400);
});
