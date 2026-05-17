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
use App\Ai\Harness\Evals\EvalReport;
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
    config()->set('worthly.harness.verifier.enabled', false);
    Cache::store('array')->flush();

    putenv('WORTHLY_RUN_EVALS=1');
});

afterEach(function () {
    putenv('WORTHLY_RUN_EVALS');
});

function evalRetriever(string $url, string $host): Retriever
{
    return new class($url, $host) implements Retriever
    {
        public function __construct(private string $url, private string $host) {}

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
                new EvidenceItem('reviews', $this->url, 'Review of '.$query->productName, $query->productName.' is great', null, 0.92, 0.8),
            ];
        }
    };
}

function evalEnricher(string $productName): QueryEnricher
{
    return new class($productName) extends QueryEnricher
    {
        public function __construct(private string $productName) {}

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
                intent: Intent::BuyDecision,
                subQueries: ['review'],
                hydePassages: [],
            );
        }
    };
}

function evalReviewer(string $decision): ProductReviewer
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
                        'name' => $query->productName,
                        'category' => 'smartphone',
                        'estimated_price_range' => 'R$ 5000 - R$ 6000',
                    ],
                    'summary' => "{$query->productName} is great",
                    'similar_products' => [],
                    'cost_benefit_analysis' => 'Fair value.',
                    'recommendation' => [
                        'decision' => $this->decision,
                        'reason' => 'good',
                    ],
                    'confidence' => 'high',
                ],
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

it('writes a per-row + aggregate eval report artifact when WORTHLY_RUN_EVALS=1 and the pipeline is fully faked', function () {
    Storage::fake('local');

    $dataset = [
        ['id' => 'row1', 'input' => 'iPhone 15 worth it', 'expected_decision' => 'buy', 'acceptable_decisions' => ['buy'], 'expected_must_cite_domains' => ['rtings.com']],
        ['id' => 'row2', 'input' => 'iPhone 16 worth it', 'expected_decision' => 'buy', 'acceptable_decisions' => ['buy'], 'expected_must_cite_domains' => ['rtings.com']],
    ];

    $user = User::factory()->create();

    $report = new EvalReport;

    $whitelist = (array) config('worthly.harness.retrievers.reviews.include_domains', []);

    foreach ($dataset as $idx => $row) {
        $productName = $idx === 0 ? 'iPhone 15' : 'iPhone 16';
        $decision = $idx === 0 ? 'buy' : 'consider_alternatives';

        app()->instance(QueryEnricher::class, evalEnricher($productName));
        app()->instance(ProductIdentifier::class, app(ProductIdentifier::class));
        app()->instance(ProductReviewer::class, evalReviewer($decision));
        app()->instance(EvidenceVerifier::class, app(EvidenceVerifier::class));
        app()->bind(RetrievalRouter::class, fn () => new RetrievalRouter([
            evalRetriever('https://www.rtings.com/'.$productName, 'rtings.com'),
        ]));
        app()->bind(Reranker::class, NullReranker::class);

        $analysis = app(AnalysisPipeline::class)->analyzeText($user, $row['input']);

        $decisionMatch = in_array($analysis->recommendationDecision->slug, $row['acceptable_decisions'], true);

        $citedHigh = false;
        foreach ($analysis->sources as $s) {
            $host = strtolower(preg_replace('/^www\./', '', (string) parse_url($s->url, PHP_URL_HOST)));
            foreach ($whitelist as $d) {
                if ($host === $d || str_ends_with($host, '.'.$d)) {
                    $citedHigh = true;
                    break 2;
                }
            }
        }

        $report->addRow([
            'id' => $row['id'],
            'expected_decision' => $row['expected_decision'],
            'actual_decision' => $analysis->recommendationDecision->slug,
            'decision_match' => $decisionMatch,
            'cited_high_authority' => $citedHigh,
        ]);
    }

    $filename = $report->write();

    expect(Storage::disk('local')->exists($filename))->toBeTrue();

    $payload = json_decode((string) Storage::disk('local')->get($filename), true);

    expect($payload)->toBeArray();
    expect($payload['rows'])->toHaveCount(2);
    expect($payload['rows'][0])->toHaveKey('decision_match');
    expect($payload['rows'][1])->toHaveKey('cited_high_authority');

    expect($payload['aggregate'])->toHaveKey('decision_match_rate');
    expect($payload['aggregate'])->toHaveKey('high_authority_rate');
    expect((float) $payload['aggregate']['decision_match_rate'])->toBe(0.5);
    expect((float) $payload['aggregate']['high_authority_rate'])->toBe(1.0);
});
