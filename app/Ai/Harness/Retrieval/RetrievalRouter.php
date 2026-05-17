<?php

namespace App\Ai\Harness\Retrieval;

use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetrievalRouter
{
    /**
     * @var list<Retriever>
     */
    protected array $retrievers;

    protected int $callCount = 0;

    /**
     * @param  iterable<Retriever>  $retrievers
     */
    public function __construct(iterable $retrievers = [])
    {
        $list = [];
        foreach ($retrievers as $retriever) {
            $list[] = $retriever;
        }
        $this->retrievers = $list;
    }

    /**
     * @return list<Retriever>
     */
    public function retrievers(): array
    {
        return $this->retrievers;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function gather(EnrichedQuery $query): EvidenceBundle
    {
        $this->callCount = 0;

        $context = $this->buildContext($query);
        $items = [];

        foreach ($this->retrievers as $retriever) {
            if (! $retriever->isEligible($query)) {
                continue;
            }

            $this->callCount++;

            try {
                $retrieved = $this->fetchWithCache($retriever, $query, $context);
            } catch (Throwable $e) {
                Log::warning('Retriever failed', [
                    'retriever' => $retriever->name(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($retrieved as $item) {
                $items[] = $item;
            }
        }

        $items = $this->dedupByUrl($items);
        $items = $this->capGlobal($items);

        return new EvidenceBundle(array_values($items));
    }

    protected function buildContext(EnrichedQuery $query): RetrievalContext
    {
        return new RetrievalContext(
            region: $query->region,
            maxItemsPerAdapter: 8,
            perAdapterTimeoutMs: 4000,
        );
    }

    /**
     * @return list<EvidenceItem>
     */
    protected function fetchWithCache(Retriever $retriever, EnrichedQuery $query, RetrievalContext $context): array
    {
        $channel = $retriever->name();
        $ttl = (int) config("worthly.harness.cache.retrieval_ttl.{$channel}", 3600);

        $key = $this->cacheKey($retriever, $query);

        if ($ttl <= 0) {
            return $retriever->retrieve($query, $context);
        }

        return Cache::remember($key, $ttl, fn () => $retriever->retrieve($query, $context));
    }

    protected function cacheKey(Retriever $retriever, EnrichedQuery $query): string
    {
        $payload = json_encode([
            'channel' => $retriever->name(),
            'raw' => $query->rawQuery,
            'product' => $query->productName,
            'brand' => $query->brand,
            'category' => $query->category,
            'region' => $query->region,
            'sub_queries' => $query->subQueries,
        ]);

        return 'worthly:r:'.$retriever->name().':'.sha1($payload ?: '');
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    protected function dedupByUrl(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            if (isset($seen[$item->url])) {
                continue;
            }

            $seen[$item->url] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    protected function capGlobal(array $items): array
    {
        $cap = (int) config('worthly.harness.budget.max_retrieval_calls', 30);

        if ($cap > 0 && count($items) > $cap * 5) {
            $items = array_slice($items, 0, $cap * 5);
        }

        return $items;
    }
}
