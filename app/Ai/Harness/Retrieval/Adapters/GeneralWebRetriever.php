<?php

namespace App\Ai\Harness\Retrieval\Adapters;

use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use Carbon\CarbonImmutable;
use Throwable;

class GeneralWebRetriever implements Retriever
{
    public const CHANNEL = 'general';

    public function __construct(protected TavilyClient $tavily) {}

    public function name(): string
    {
        return self::CHANNEL;
    }

    public function isEligible(EnrichedQuery $query): bool
    {
        return true;
    }

    public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array
    {
        $timeout = (int) config('worthly.harness.retrievers.general.timeout_ms', $ctx->perAdapterTimeoutMs);

        $payload = $this->tavily->search(
            query: $this->buildQuery($query),
            includeDomains: [],
            maxResults: $ctx->maxItemsPerAdapter,
            timeoutMs: $timeout,
        );

        $results = $payload['results'] ?? [];
        $authorityMap = (array) config('worthly.harness.authority', []);

        $items = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = isset($row['url']) ? (string) $row['url'] : null;
            $title = isset($row['title']) ? (string) $row['title'] : null;

            if (! $url || ! $title) {
                continue;
            }

            $host = $this->host($url);

            $items[] = new EvidenceItem(
                sourceChannel: self::CHANNEL,
                url: $url,
                title: $title,
                snippet: (string) ($row['content'] ?? $row['snippet'] ?? ''),
                publishedAt: $this->parseDate($row['published_date'] ?? null),
                authorityScore: $this->authorityFor($host, $authorityMap),
                rawRelevance: isset($row['score']) ? (float) $row['score'] : 0.0,
            );
        }

        return $items;
    }

    protected function buildQuery(EnrichedQuery $query): string
    {
        return $query->productName !== null
            ? $query->productName.' opinion review'
            : $query->rawQuery;
    }

    protected function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return '';
        }

        return strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }

    /**
     * @param  array<string, float>  $authorityMap
     */
    protected function authorityFor(string $host, array $authorityMap): float
    {
        if ($host !== '' && isset($authorityMap[$host])) {
            return (float) $authorityMap[$host];
        }

        return 0.4;
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
