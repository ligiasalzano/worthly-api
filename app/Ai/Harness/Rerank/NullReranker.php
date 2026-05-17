<?php

namespace App\Ai\Harness\Rerank;

use App\Ai\Harness\Contracts\Reranker;
use App\Ai\Harness\Dto\EnrichedQuery;

class NullReranker implements Reranker
{
    public function rerank(EnrichedQuery $query, array $items, int $topK): array
    {
        if ($topK <= 0) {
            return [];
        }

        return array_values(array_slice($items, 0, $topK));
    }
}
