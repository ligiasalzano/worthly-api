<?php

namespace App\Ai\Harness\Rerank;

use App\Ai\Harness\Contracts\Reranker;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Rerank\Filters\AuthorityFloorFilter;
use App\Ai\Harness\Rerank\Filters\ChannelDiversityFilter;
use App\Ai\Harness\Rerank\Filters\RecencyDecayFilter;
use App\Ai\Harness\Rerank\Filters\SemanticDedupFilter;

class RerankPipeline
{
    protected bool $degraded = false;

    public function __construct(
        protected Reranker $reranker,
        protected SemanticDedupFilter $dedup,
        protected AuthorityFloorFilter $authorityFloor,
        protected RecencyDecayFilter $recencyDecay,
        protected ChannelDiversityFilter $diversity,
    ) {}

    public function process(EnrichedQuery $query, EvidenceBundle $bundle): EvidenceBundle
    {
        $this->degraded = false;

        if ($bundle->isEmpty()) {
            return EvidenceBundle::empty();
        }

        $topK = (int) config('worthly.harness.rerank.top_k', 8);
        $items = $bundle->items;

        $reranked = $this->reranker->rerank($query, $items, count($items));

        if ($this->reranker instanceof CohereReranker && $this->reranker->wasDegraded()) {
            $this->degraded = true;
        }

        $filtered = $this->dedup->apply($reranked);
        $filtered = $this->authorityFloor->apply($filtered);
        $filtered = $this->recencyDecay->apply($filtered);
        $filtered = $this->diversity->apply($filtered, $topK);

        return new EvidenceBundle(array_values(array_slice($filtered, 0, $topK)));
    }

    public function wasDegraded(): bool
    {
        return $this->degraded;
    }
}
