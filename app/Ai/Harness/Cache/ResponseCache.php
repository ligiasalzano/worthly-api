<?php

namespace App\Ai\Harness\Cache;

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StructuredAgentResponse;

class ResponseCache
{
    public function key(EnrichedQuery $query, EvidenceBundle $bundle): string
    {
        $payload = json_encode([
            'raw_query' => $query->rawQuery,
            'product_name' => $query->productName,
            'brand' => $query->brand,
            'category' => $query->category,
            'region' => $query->region,
            'use_case' => $query->useCase,
            'budget_hint' => $query->budgetHint,
            'intent' => $query->intent->value,
            'sub_queries' => $query->subQueries,
            'evidence_ids' => $bundle->ids(),
        ], JSON_UNESCAPED_UNICODE);

        return 'worthly:resp:'.sha1($payload ?: '');
    }

    public function ttl(): int
    {
        return (int) config('worthly.harness.cache.response_ttl', 86400);
    }

    public function get(EnrichedQuery $query, EvidenceBundle $bundle): ?StructuredAgentResponse
    {
        $value = Cache::get($this->key($query, $bundle));

        return $value instanceof StructuredAgentResponse ? $value : null;
    }

    public function put(EnrichedQuery $query, EvidenceBundle $bundle, StructuredAgentResponse $response): void
    {
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return;
        }

        Cache::put($this->key($query, $bundle), $response, $ttl);
    }
}
