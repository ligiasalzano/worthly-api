<?php

namespace App\Ai\Harness\Contracts;

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;

interface Reranker
{
    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    public function rerank(EnrichedQuery $query, array $items, int $topK): array;
}
