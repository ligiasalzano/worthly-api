<?php

namespace App\Ai\Harness;

use App\Ai\Agents\ProductIdentifier;
use App\Ai\Agents\ProductReviewer;
use App\Ai\Agents\QueryEnricher;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
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
use Throwable;

class AnalysisPipeline
{
    public function __construct(
        protected QueryEnricher $enricher,
        protected ProductIdentifier $identifier,
        protected RetrievalRouter $router,
        protected ProductReviewer $reviewer,
    ) {}

    public function analyzeText(User $user, string $query): Analysis
    {
        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);

        $enriched = $this->enricher->enrich($query);

        if ($this->isUnidentified($enriched)) {
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

        $bundle = $this->router->gather($enriched);
        $retrievalCalls = $this->router->callCount();

        if ($bundle->isEmpty()) {
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

        $response = $this->callReviewer($enriched, $bundle);

        return $this->persistAgentResponse(
            user: $user,
            inputTypeSlug: 'text',
            query: $query,
            imagePath: null,
            enriched: $enriched,
            evidenceBundle: $bundle,
            response: $response,
            retrievalCalls: $retrievalCalls,
            startedAt: $startedAt,
            startMs: $startMs,
        );
    }

    public function analyzeImage(User $user, string $imagePath): Analysis
    {
        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);

        $enriched = $this->identifier->identify($imagePath);

        if ($this->isUnidentified($enriched)) {
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

        $bundle = $this->router->gather($enriched);
        $retrievalCalls = $this->router->callCount();

        if ($bundle->isEmpty()) {
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

        $response = $this->callReviewer($enriched, $bundle);

        return $this->persistAgentResponse(
            user: $user,
            inputTypeSlug: 'image',
            query: null,
            imagePath: $imagePath,
            enriched: $enriched,
            evidenceBundle: $bundle,
            response: $response,
            retrievalCalls: $retrievalCalls,
            startedAt: $startedAt,
            startMs: $startMs,
        );
    }

    protected function isUnidentified(EnrichedQuery $query): bool
    {
        return $query->productName === null || $query->intent === Intent::Unknown;
    }

    protected function callReviewer(EnrichedQuery $query, EvidenceBundle $bundle)
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

    protected function persistAgentResponse(
        User $user,
        string $inputTypeSlug,
        ?string $query,
        ?string $imagePath,
        EnrichedQuery $enriched,
        EvidenceBundle $evidenceBundle,
        $response,
        int $retrievalCalls,
        Carbon $startedAt,
        int $startMs,
    ): Analysis {
        return DB::transaction(function () use ($user, $inputTypeSlug, $query, $imagePath, $enriched, $evidenceBundle, $response, $retrievalCalls, $startedAt, $startMs) {
            $data = $response->structured;

            $inputType = InputType::firstWhere('slug', $inputTypeSlug);
            $decision = RecommendationDecision::firstWhere('slug', $data['recommendation']['decision'])
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
                'degraded' => false,
            ]);

            foreach ($data['similar_products'] ?? [] as $index => $similar) {
                $analysis->similarProducts()->create([
                    'name' => $similar['name'],
                    'reason' => $similar['reason'],
                    'price_reference' => $similar['price_reference'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $this->recordHarnessRun(
                analysis: $analysis,
                retrievalCalls: $retrievalCalls,
                evidenceCount: $evidenceBundle->count(),
                degraded: false,
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

        return HarnessRun::create([
            'analysis_id' => $analysis->id,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'total_ms' => $totalMs,
            'llm_calls' => 0,
            'retrieval_calls' => $retrievalCalls,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cache_hit' => false,
            'degraded' => $degraded,
            'budget_exhausted' => false,
            'error' => null,
            'layers' => [
                'evidence_count' => $evidenceCount,
            ],
        ]);
    }
}
