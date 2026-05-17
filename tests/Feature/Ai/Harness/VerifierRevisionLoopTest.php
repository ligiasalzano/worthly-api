<?php

use App\Ai\Agents\EvidenceVerifier;
use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\AnalysisPipeline;
use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Dto\VerificationReport;
use App\Ai\Harness\Rerank\NullReranker;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use App\Enums\Intent;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);

    config()->set('worthly.harness.verifier.enabled', true);
    config()->set('worthly.harness.verifier.max_revisions', 1);
});

function revisionLoopEnricher(): QueryEnricher
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

function revisionLoopRetriever(array $items): Retriever
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

function revisionLoopBundleItems(): array
{
    return [
        new EvidenceItem('reviews', 'https://example.test/a', 'iPhone 15 review', 'iPhone battery great', null, 0.9, 0.8),
        new EvidenceItem('reviews', 'https://example.test/b', 'iPhone 15 specs', 'iPhone camera details', null, 0.9, 0.7),
    ];
}

function revisionLoopResponse(string $summary, string $confidence): StructuredAgentResponse
{
    return new StructuredAgentResponse(
        invocationId: (string) Str::uuid7(),
        structured: [
            'product' => [
                'name' => 'iPhone 15',
                'category' => 'smartphone',
                'estimated_price_range' => 'R$ 5000 - R$ 6000',
            ],
            'summary' => $summary,
            'similar_products' => [],
            'cost_benefit_analysis' => 'priced fairly',
            'recommendation' => [
                'decision' => 'buy',
                'reason' => 'great phone',
            ],
            'confidence' => $confidence,
        ],
        text: 'fake',
        usage: new Usage(0, 0, 0, 0, 0),
        meta: new Meta,
    );
}

class TwoCallProductReviewerSpy extends ProductReviewer
{
    public int $calls = 0;

    /** @var array<int, StructuredAgentResponse> */
    public array $responses;

    public function __construct(StructuredAgentResponse $first, StructuredAgentResponse $second)
    {
        $this->responses = [$first, $second];
    }

    public function recommend(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
    {
        $index = $this->calls;
        $this->calls++;

        return $this->responses[$index] ?? $this->responses[array_key_last($this->responses)];
    }
}

class SequencedEvidenceVerifierSpy extends EvidenceVerifier
{
    public int $calls = 0;

    /** @var array<int, VerificationReport> */
    public array $reports;

    public function __construct(VerificationReport ...$reports)
    {
        $this->reports = $reports;
    }

    public function verify(array $structuredOutput, EvidenceBundle $bundle): VerificationReport
    {
        $index = $this->calls;
        $this->calls++;

        return $this->reports[$index] ?? $this->reports[array_key_last($this->reports)];
    }
}

function bindRevisionLoopPipeline(TwoCallProductReviewerSpy $reviewer, SequencedEvidenceVerifierSpy $verifier): void
{
    app()->instance(QueryEnricher::class, revisionLoopEnricher());
    app()->instance(ProductIdentifier::class, app(ProductIdentifier::class));
    app()->instance(ProductReviewer::class, $reviewer);
    app()->instance(EvidenceVerifier::class, $verifier);

    app()->bind(RetrievalRouter::class, fn () => new RetrievalRouter([
        revisionLoopRetriever(revisionLoopBundleItems()),
    ]));

    app()->bind(Reranker::class, NullReranker::class);
}

it('runs at most one revision when the verifier flags an unsupported claim that is then resolved, keeping confidence unchanged', function () {
    $user = User::factory()->create();

    $reviewer = new TwoCallProductReviewerSpy(
        first: revisionLoopResponse('speculative claim', 'medium'),
        second: revisionLoopResponse('iPhone battery is excellent', 'medium'),
    );

    $verifier = new SequencedEvidenceVerifierSpy(
        new VerificationReport([
            ['field' => 'summary', 'status' => 'unsupported', 'evidence_ids' => []],
        ]),
        new VerificationReport([
            ['field' => 'summary', 'status' => 'supported', 'evidence_ids' => ['S1']],
        ]),
    );

    bindRevisionLoopPipeline($reviewer, $verifier);

    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 review');

    expect($reviewer->calls)->toBe(2);
    expect($verifier->calls)->toBe(2);
    expect($analysis->confidence)->toBe('medium');
    expect($analysis->summary)->toBe('iPhone battery is excellent');
});

it('downgrades confidence to low and strips the unsupported field when revision still fails', function () {
    $user = User::factory()->create();

    $reviewer = new TwoCallProductReviewerSpy(
        first: revisionLoopResponse('speculative claim', 'medium'),
        second: revisionLoopResponse('another speculative claim', 'medium'),
    );

    $verifier = new SequencedEvidenceVerifierSpy(
        new VerificationReport([
            ['field' => 'summary', 'status' => 'unsupported', 'evidence_ids' => []],
        ]),
        new VerificationReport([
            ['field' => 'summary', 'status' => 'unsupported', 'evidence_ids' => []],
        ]),
    );

    bindRevisionLoopPipeline($reviewer, $verifier);

    $analysis = app(AnalysisPipeline::class)->analyzeText($user, 'iPhone 15 review');

    expect($reviewer->calls)->toBe(2);
    expect($verifier->calls)->toBe(2);
    expect($analysis->confidence)->toBe('low');
    expect($analysis->summary)->toBeNull();
});
