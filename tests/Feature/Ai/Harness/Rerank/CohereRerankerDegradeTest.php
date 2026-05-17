<?php

use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\AnalysisPipeline;
use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Rerank\CohereReranker;
use App\Ai\Harness\Rerank\EmbeddingClient;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use App\Enums\Intent;
use App\Models\HarnessRun;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Env;
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

    $this->previousCohereKey = Env::getRepository()->get('COHERE_API_KEY');
    Env::getRepository()->set('COHERE_API_KEY', 'co-fake-token');

    config()->set('worthly.harness.rerank.model', 'rerank-v3.5');
    config()->set('worthly.harness.rerank.top_k', 8);
});

afterEach(function () {
    if ($this->previousCohereKey === false) {
        Env::getRepository()->clear('COHERE_API_KEY');
    } else {
        Env::getRepository()->set('COHERE_API_KEY', $this->previousCohereKey);
    }
});

function degradeStubEmbeddingClient(): EmbeddingClient
{
    return new class extends EmbeddingClient
    {
        public function embed(string $text): array
        {
            $bytes = md5($text, true);
            $vec = [];
            for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
                $byte = ord($bytes[$i]);
                $vec[] = (float) ($byte >= 128 ? $byte - 256 : $byte);
            }

            return $vec;
        }
    };
}

function degradeFakeQueryEnricher(): QueryEnricher
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

function degradeFakeRetriever(array $items): Retriever
{
    return new class($items) implements Retriever
    {
        public function __construct(private array $items) {}

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
            return $this->items;
        }
    };
}

class DegradeCapturingProductReviewer extends ProductReviewer
{
    public ?EvidenceBundle $captured = null;

    public function recommend(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
    {
        $this->captured = $bundle;

        return new StructuredAgentResponse(
            invocationId: (string) Str::uuid7(),
            structured: [
                'product' => [
                    'name' => $query->productName ?? 'Unknown',
                    'category' => $query->category,
                    'estimated_price_range' => null,
                ],
                'summary' => 'fake',
                'similar_products' => [],
                'cost_benefit_analysis' => null,
                'recommendation' => [
                    'decision' => 'buy',
                    'reason' => 'fake',
                ],
                'confidence' => 'medium',
            ],
            text: 'fake',
            usage: new Usage(0, 0, 0, 0, 0),
            meta: new Meta,
        );
    }
}

it('falls back to NullReranker on Cohere 500, completes the pipeline, and flags the run as degraded', function () {
    $user = User::factory()->create();

    $items = [
        new EvidenceItem('reviews', 'https://example.test/a', 'Alpha', 'snippet alpha', null, 0.8, 0.9),
        new EvidenceItem('reviews', 'https://example.test/b', 'Bravo', 'snippet bravo', null, 0.8, 0.8),
        new EvidenceItem('reviews', 'https://example.test/c', 'Charlie', 'snippet charlie', null, 0.8, 0.7),
    ];

    Http::fake([
        CohereReranker::ENDPOINT => Http::response(['error' => 'boom'], 500),
    ]);

    $this->app->instance(EmbeddingClient::class, degradeStubEmbeddingClient());
    $this->app->instance(QueryEnricher::class, degradeFakeQueryEnricher());
    $this->app->instance(ProductIdentifier::class, app(ProductIdentifier::class));

    $reviewer = new DegradeCapturingProductReviewer;
    $this->app->instance(ProductReviewer::class, $reviewer);

    $this->app->bind(RetrievalRouter::class, fn () => new RetrievalRouter([degradeFakeRetriever($items)]));

    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 review');

    expect($analysis)->not->toBeNull();
    expect($analysis->degraded)->toBeTrue();

    $run = HarnessRun::where('analysis_id', $analysis->id)->first();
    expect($run)->not->toBeNull();
    expect($run->degraded)->toBeTrue();

    expect($reviewer->captured)->not->toBeNull();
    $captured = $reviewer->captured;
    expect($captured->count())->toBe(3);
    expect(array_map(fn ($i) => $i->title, $captured->items))
        ->toBe(['Alpha', 'Bravo', 'Charlie']);

    foreach ($captured->items as $item) {
        expect($item->rerankScore)->toBeNull();
    }

    Http::assertSent(fn ($req) => $req->url() === CohereReranker::ENDPOINT && $req->method() === 'POST');
});
