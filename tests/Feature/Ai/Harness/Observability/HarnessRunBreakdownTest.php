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
use App\Ai\Harness\Dto\VerificationReport;
use App\Ai\Harness\Rerank\NullReranker;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use App\Enums\Intent;
use App\Models\HarnessRun;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Facades\Cache;
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
    config()->set('worthly.harness.verifier.enabled', true);
    config()->set('worthly.harness.verifier.max_revisions', 1);
    Cache::store('array')->flush();
});

function breakdownEnricher(): QueryEnricher
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

function breakdownRetriever(): Retriever
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

function breakdownReviewer(): ProductReviewer
{
    return new class extends ProductReviewer
    {
        public function recommend(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
        {
            return new StructuredAgentResponse(
                invocationId: (string) Str::uuid7(),
                structured: [
                    'product' => [
                        'name' => 'iPhone 15',
                        'category' => 'smartphone',
                        'estimated_price_range' => 'R$ 5000 - R$ 6000',
                    ],
                    'summary' => 'Solid phone.',
                    'similar_products' => [],
                    'cost_benefit_analysis' => 'Fair.',
                    'recommendation' => ['decision' => 'buy', 'reason' => 'Great.'],
                    'confidence' => 'medium',
                ],
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

function breakdownVerifier(): EvidenceVerifier
{
    return new class extends EvidenceVerifier
    {
        public function verify(array $structuredOutput, EvidenceBundle $bundle): VerificationReport
        {
            return new VerificationReport([
                ['field' => 'summary', 'status' => 'supported', 'evidence_ids' => ['S1']],
            ]);
        }
    };
}

function bindBreakdownPipeline(): void
{
    app()->instance(QueryEnricher::class, breakdownEnricher());
    app()->instance(ProductIdentifier::class, app(ProductIdentifier::class));
    app()->instance(ProductReviewer::class, breakdownReviewer());
    app()->instance(EvidenceVerifier::class, breakdownVerifier());

    app()->bind(RetrievalRouter::class, fn () => new RetrievalRouter([breakdownRetriever()]));
    app()->bind(Reranker::class, NullReranker::class);
}

it('writes a layers jsonb breakdown to harness_runs with l1..l5 entries containing duration_ms and success', function () {
    bindBreakdownPipeline();

    $user = User::factory()->create();
    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 review');

    $run = HarnessRun::query()->where('analysis_id', $analysis->id)->firstOrFail();

    $layers = $run->layers;

    expect($layers)->toBeArray();

    foreach (['l1', 'l2', 'l3', 'l4', 'l5'] as $layer) {
        expect($layers)->toHaveKey($layer);
        expect($layers[$layer])->toHaveKey('duration_ms');
        expect($layers[$layer]['duration_ms'])->toBeInt();
        expect($layers[$layer])->toHaveKey('success');
        expect($layers[$layer]['success'])->toBeBool();
    }
});
