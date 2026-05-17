<?php

namespace App\Ai\Harness;

use App\Ai\Agents\EvidenceVerifier;
use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\Cache\ResponseCache;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Observability\LayerTelemetry;
use App\Ai\Harness\Rerank\RerankPipeline;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use App\Enums\Intent;
use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use App\Exceptions\LlmProviderException;
use App\Models\Analysis;
use App\Models\HarnessRun;
use App\Models\InputType;
use App\Models\RecommendationDecision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class AnalysisPipeline
{
    public function __construct(
        protected QueryEnricher $enricher,
        protected ProductIdentifier $identifier,
        protected RetrievalRouter $router,
        protected RerankPipeline $rerank,
        protected ProductReviewer $reviewer,
        protected EvidenceVerifier $verifier,
        protected CitationPostProcessor $citationPostProcessor,
        protected ResponseCache $responseCache,
        protected LayerTelemetry $telemetry,
    ) {}

    public function analyzeText(User $user, string $query): Analysis
    {
        $this->telemetry->reset();

        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);

        $enriched = $this->telemetry->record(
            'l1',
            ['items_in' => 1, 'items_out' => 1],
            fn () => $this->enricher->enrich($query),
        );

        if ($this->isUnidentified($enriched)) {
            $this->telemetry->markSkipped('l2');
            $this->telemetry->markSkipped('l3');
            $this->telemetry->markSkipped('l4');
            $this->telemetry->markSkipped('l5');

            return $this->persistInsufficientEvidence(
                user: $user,
                inputTypeSlug: 'text',
                query: $query,
                imagePath: null,
                enriched: $enriched,
                evidenceBundle: EvidenceBundle::empty(),
                retrievalCalls: 0,
                startedAt: $startedAt,
                startMs: $startMs,
            );
        }

        $bundle = $this->telemetry->record(
            'l2',
            ['items_in' => 0, 'items_out' => 0],
            fn () => $this->router->gather($enriched),
        );
        $retrievalCalls = $this->router->callCount();
        $this->updateLayerCounts('l2', itemsIn: 0, itemsOut: $bundle->count());

        if ($bundle->isEmpty()) {
            $this->telemetry->markSkipped('l3');
            $this->telemetry->markSkipped('l4');
            $this->telemetry->markSkipped('l5');

            return $this->persistInsufficientEvidence(
                user: $user,
                inputTypeSlug: 'text',
                query: $query,
                imagePath: null,
                enriched: $enriched,
                evidenceBundle: $bundle,
                retrievalCalls: $retrievalCalls,
                startedAt: $startedAt,
                startMs: $startMs,
            );
        }

        $rawCount = $bundle->count();

        $rerankedBundle = $this->telemetry->record(
            'l3',
            ['items_in' => $rawCount, 'items_out' => 0],
            fn () => $this->rerank->process($enriched, $bundle),
        );
        $bundle = $rerankedBundle;
        $this->updateLayerCounts('l3', itemsIn: $rawCount, itemsOut: $bundle->count());
        $rerankDegraded = $this->rerank->wasDegraded();

        $response = $this->generate($enriched, $bundle, useCache: true);
        $response = $this->finalizeResponse($enriched, $bundle, $response);

        return $this->persistAgentResponse(
            user: $user,
            inputTypeSlug: 'text',
            query: $query,
            imagePath: null,
            enriched: $enriched,
            evidenceBundle: $bundle,
            response: $response,
            retrievalCalls: $retrievalCalls,
            degraded: $rerankDegraded,
            startedAt: $startedAt,
            startMs: $startMs,
        );
    }

    public function analyzeImage(User $user, string $imagePath): Analysis
    {
        $this->telemetry->reset();

        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);

        $enriched = $this->telemetry->record(
            'l1',
            ['items_in' => 1, 'items_out' => 1],
            fn () => $this->identifier->identify($imagePath),
        );

        if ($this->isUnidentified($enriched)) {
            $this->telemetry->markSkipped('l2');
            $this->telemetry->markSkipped('l3');
            $this->telemetry->markSkipped('l4');
            $this->telemetry->markSkipped('l5');

            return $this->persistInsufficientEvidence(
                user: $user,
                inputTypeSlug: 'image',
                query: null,
                imagePath: $imagePath,
                enriched: $enriched,
                evidenceBundle: EvidenceBundle::empty(),
                retrievalCalls: 0,
                startedAt: $startedAt,
                startMs: $startMs,
            );
        }

        $bundle = $this->telemetry->record(
            'l2',
            ['items_in' => 0, 'items_out' => 0],
            fn () => $this->router->gather($enriched),
        );
        $retrievalCalls = $this->router->callCount();
        $this->updateLayerCounts('l2', itemsIn: 0, itemsOut: $bundle->count());

        if ($bundle->isEmpty()) {
            $this->telemetry->markSkipped('l3');
            $this->telemetry->markSkipped('l4');
            $this->telemetry->markSkipped('l5');

            return $this->persistInsufficientEvidence(
                user: $user,
                inputTypeSlug: 'image',
                query: null,
                imagePath: $imagePath,
                enriched: $enriched,
                evidenceBundle: $bundle,
                retrievalCalls: $retrievalCalls,
                startedAt: $startedAt,
                startMs: $startMs,
            );
        }

        $rawCount = $bundle->count();

        $rerankedBundle = $this->telemetry->record(
            'l3',
            ['items_in' => $rawCount, 'items_out' => 0],
            fn () => $this->rerank->process($enriched, $bundle),
        );
        $bundle = $rerankedBundle;
        $this->updateLayerCounts('l3', itemsIn: $rawCount, itemsOut: $bundle->count());
        $rerankDegraded = $this->rerank->wasDegraded();

        $response = $this->generate($enriched, $bundle, useCache: false);
        $response = $this->finalizeResponse($enriched, $bundle, $response);

        return $this->persistAgentResponse(
            user: $user,
            inputTypeSlug: 'image',
            query: null,
            imagePath: $imagePath,
            enriched: $enriched,
            evidenceBundle: $bundle,
            response: $response,
            retrievalCalls: $retrievalCalls,
            degraded: $rerankDegraded,
            startedAt: $startedAt,
            startMs: $startMs,
        );
    }

    protected function isUnidentified(EnrichedQuery $query): bool
    {
        return $query->productName === null || $query->intent === Intent::Unknown;
    }

    protected function generate(EnrichedQuery $query, EvidenceBundle $bundle, bool $useCache): StructuredAgentResponse
    {
        $cacheHit = false;
        $cached = $useCache ? $this->responseCache->get($query, $bundle) : null;

        $response = $this->telemetry->record(
            'l4',
            ['items_in' => $bundle->count(), 'items_out' => $bundle->count(), 'cache_hit' => $cached !== null],
            function () use ($query, $bundle, $useCache, $cached, &$cacheHit) {
                if ($cached !== null) {
                    $cacheHit = true;

                    return $cached;
                }

                $generated = $this->callReviewer($query, $bundle);

                if ($useCache) {
                    $this->responseCache->put($query, $bundle, $generated);
                }

                return $generated;
            },
        );

        if ($cacheHit) {
            $this->markLayerCacheHit('l4');
        }

        return $response;
    }

    protected function callReviewer(EnrichedQuery $query, EvidenceBundle $bundle): StructuredAgentResponse
    {
        try {
            return $this->reviewer->recommend($query, $bundle);
        } catch (FailoverableException $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_failed',
                message: 'The LLM provider failed to complete the analysis.',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new LlmProviderException(
                errorCode: 'llm_provider_error',
                message: 'An unexpected error occurred while analyzing the product.',
                previous: $e,
            );
        }
    }

    protected function finalizeResponse(
        EnrichedQuery $query,
        EvidenceBundle $bundle,
        StructuredAgentResponse $response,
    ): StructuredAgentResponse {
        if ($this->verifierEnabled()) {
            $response = $this->telemetry->record(
                'l5',
                ['items_in' => $bundle->count(), 'items_out' => $bundle->count()],
                fn () => $this->runVerifierLoop($query, $bundle, $response),
            );
        } else {
            $this->telemetry->markSkipped('l5');
        }

        $processed = $this->citationPostProcessor->process($response->structured, $bundle);
        $response->structured = $processed['output'];

        return $response;
    }

    protected function verifierEnabled(): bool
    {
        return (bool) config('worthly.harness.verifier.enabled', false);
    }

    protected function runVerifierLoop(
        EnrichedQuery $query,
        EvidenceBundle $bundle,
        StructuredAgentResponse $response,
    ): StructuredAgentResponse {
        $report = $this->verifier->verify($response->structured, $bundle);

        if (! $report->hasUnsupported()) {
            return $response;
        }

        $maxRevisions = (int) config('worthly.harness.verifier.max_revisions', 1);

        if ($maxRevisions < 1) {
            $response->structured = $this->stripUnsupportedFields(
                $response->structured,
                $report->unsupportedFields(),
            );

            return $response;
        }

        $revised = $this->callReviewer($query, $bundle);
        $revisedReport = $this->verifier->verify($revised->structured, $bundle);

        if (! $revisedReport->hasUnsupported()) {
            return $revised;
        }

        $revised->structured = $this->stripUnsupportedFields(
            $revised->structured,
            $revisedReport->unsupportedFields(),
        );

        return $revised;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function stripUnsupportedFields(array $structured, array $fields): array
    {
        $structured['confidence'] = 'low';

        foreach ($fields as $field) {
            $structured = $this->stripField($structured, $field);
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    protected function stripField(array $structured, string $field): array
    {
        switch ($field) {
            case 'summary':
                $structured['summary'] = null;
                break;
            case 'cost_benefit':
            case 'cost_benefit_analysis':
                $structured['cost_benefit_analysis'] = null;
                break;
            case 'similar_products':
                $structured['similar_products'] = [];
                break;
        }

        if (isset($structured['sources_used']) && is_array($structured['sources_used'])) {
            $structured['sources_used'] = array_values(array_filter(
                $structured['sources_used'],
                fn ($entry) => ! is_array($entry) || ($entry['field'] ?? null) !== $field,
            ));
        }

        return $structured;
    }

    protected function persistAgentResponse(
        User $user,
        string $inputTypeSlug,
        ?string $query,
        ?string $imagePath,
        EnrichedQuery $enriched,
        EvidenceBundle $evidenceBundle,
        $response,
        int $retrievalCalls,
        bool $degraded,
        Carbon $startedAt,
        int $startMs,
    ): Analysis {
        return DB::transaction(function () use ($user, $inputTypeSlug, $query, $imagePath, $enriched, $evidenceBundle, $response, $retrievalCalls, $degraded, $startedAt, $startMs) {
            $data = $response->structured;

            $inputType = InputType::firstWhere('slug', $inputTypeSlug);
            $decision = RecommendationDecision::firstWhere('slug', $data['recommendation']['decision'] ?? null)
                ?? RecommendationDecision::firstWhere('slug', RecommendationDecisionEnum::InsufficientEvidence->value);

            $analysis = Analysis::create([
                'user_id' => $user->id,
                'input_type_id' => $inputType->id,
                'recommendation_decision_id' => $decision->id,
                'query' => $query ?? $enriched->rawQuery,
                'image_path' => $imagePath,
                'product_name' => $data['product']['name'] ?? ($enriched->productName ?? 'Unknown product'),
                'product_category' => $data['product']['category'] ?? $enriched->category,
                'estimated_price_range' => $data['product']['estimated_price_range'] ?? null,
                'summary' => $data['summary'] ?? null,
                'cost_benefit_analysis' => $data['cost_benefit_analysis'] ?? null,
                'recommendation_reason' => $data['recommendation']['reason'] ?? null,
                'raw_response' => $data,
                'confidence' => $data['confidence'] ?? 'medium',
                'degraded' => $degraded,
            ]);

            foreach ($data['similar_products'] ?? [] as $index => $similar) {
                $analysis->similarProducts()->create([
                    'name' => $similar['name'],
                    'reason' => $similar['reason'],
                    'price_reference' => $similar['price_reference'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $this->persistAnalysisSources($analysis, $evidenceBundle);

            $this->recordHarnessRun(
                analysis: $analysis,
                retrievalCalls: $retrievalCalls,
                evidenceCount: $evidenceBundle->count(),
                degraded: $degraded,
                startedAt: $startedAt,
                startMs: $startMs,
            );

            return $analysis;
        });
    }

    protected function persistInsufficientEvidence(
        User $user,
        string $inputTypeSlug,
        ?string $query,
        ?string $imagePath,
        EnrichedQuery $enriched,
        EvidenceBundle $evidenceBundle,
        int $retrievalCalls,
        Carbon $startedAt,
        int $startMs,
    ): Analysis {
        return DB::transaction(function () use ($user, $inputTypeSlug, $query, $imagePath, $enriched, $evidenceBundle, $retrievalCalls, $startedAt, $startMs) {
            $inputType = InputType::firstWhere('slug', $inputTypeSlug);
            $decision = RecommendationDecision::firstWhere('slug', RecommendationDecisionEnum::InsufficientEvidence->value);

            $reason = $enriched->productName === null
                ? 'Product could not be identified from the provided input.'
                : 'No supporting evidence was retrieved for this product.';

            $errorCode = $enriched->productName === null
                ? 'product_not_identified'
                : 'no_evidence';

            $analysis = Analysis::create([
                'user_id' => $user->id,
                'input_type_id' => $inputType->id,
                'recommendation_decision_id' => $decision->id,
                'query' => $query ?? $enriched->rawQuery,
                'image_path' => $imagePath,
                'product_name' => $enriched->productName ?? 'Unknown product',
                'product_category' => $enriched->category,
                'estimated_price_range' => null,
                'summary' => null,
                'cost_benefit_analysis' => null,
                'recommendation_reason' => $reason,
                'raw_response' => [
                    'error_code' => $errorCode,
                    'enriched_query' => [
                        'raw_query' => $enriched->rawQuery,
                        'product_name' => $enriched->productName,
                        'brand' => $enriched->brand,
                        'category' => $enriched->category,
                        'intent' => $enriched->intent->value,
                    ],
                ],
                'confidence' => 'low',
                'degraded' => true,
            ]);

            $this->persistAnalysisSources($analysis, $evidenceBundle);

            $this->recordHarnessRun(
                analysis: $analysis,
                retrievalCalls: $retrievalCalls,
                evidenceCount: $evidenceBundle->count(),
                degraded: true,
                startedAt: $startedAt,
                startMs: $startMs,
            );

            return $analysis;
        });
    }

    protected function persistAnalysisSources(Analysis $analysis, EvidenceBundle $bundle): void
    {
        foreach ($bundle->items as $index => $item) {
            $analysis->sources()->create([
                'position' => $index,
                'source_channel' => $item->sourceChannel,
                'url' => $item->url,
                'title' => $item->title,
                'snippet' => $item->snippet,
                'authority_score' => $item->authorityScore,
                'rerank_score' => $item->rerankScore,
                'published_at' => $item->publishedAt,
            ]);
        }
    }

    protected function recordHarnessRun(
        Analysis $analysis,
        int $retrievalCalls,
        int $evidenceCount,
        bool $degraded,
        Carbon $startedAt,
        int $startMs,
    ): HarnessRun {
        $finishedAt = Carbon::now();
        $totalMs = max(0, (int) (microtime(true) * 1000) - $startMs);
        $layers = $this->telemetry->layers();
        $layers['evidence_count'] = $evidenceCount;

        return HarnessRun::create([
            'analysis_id' => $analysis->id,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'total_ms' => $totalMs,
            'llm_calls' => 0,
            'retrieval_calls' => $retrievalCalls,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cache_hit' => $this->anyLayerCacheHit(),
            'degraded' => $degraded,
            'budget_exhausted' => false,
            'error' => null,
            'layers' => $layers,
        ]);
    }

    protected function updateLayerCounts(string $layer, int $itemsIn, int $itemsOut): void
    {
        $this->telemetry->updateCounts($layer, $itemsIn, $itemsOut);
    }

    protected function markLayerCacheHit(string $layer): void
    {
        $this->telemetry->markCacheHit($layer);
    }

    protected function anyLayerCacheHit(): bool
    {
        return $this->telemetry->anyCacheHit();
    }
}
