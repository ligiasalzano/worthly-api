<?php

namespace App\Ai\Harness\Retrieval\Adapters;

use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use App\Enums\Intent;
use Carbon\CarbonImmutable;
use Throwable;

class ProfessionalReviewRetriever implements Retriever
{
    public const CHANNEL = 'reviews';

    public function __construct(protected TavilyClient $tavily) {}

    public function name(): string
    {
        return self::CHANNEL;
    }

    public function isEligible(EnrichedQuery $query): bool
    {
        return $query->intent !== Intent::Unknown;
    }

    public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array
    {
        $includeDomains = (array) config('worthly.harness.retrievers.reviews.include_domains', []);
        $timeout = (int) config('worthly.harness.retrievers.reviews.timeout_ms', $ctx->perAdapterTimeoutMs);

        $payload = $this->tavily->search(
            query: $this->buildQuery($query),
            includeDomains: $includeDomains,
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

            if ($includeDomains !== [] && ! $this->matchesWhitelist($host, $includeDomains)) {
                continue;
            }

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
        $parts = array_filter([
            $query->productName,
            $query->category,
            'review',
        ]);

        if ($parts === []) {
            return $query->rawQuery;
        }

        return implode(' ', $parts);
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
     * @param  list<string>  $whitelist
     */
    protected function matchesWhitelist(string $host, array $whitelist): bool
    {
        foreach ($whitelist as $allowed) {
            if ($host === strtolower($allowed) || str_ends_with($host, '.'.strtolower($allowed))) {
                return true;
            }
        }

        return false;
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
