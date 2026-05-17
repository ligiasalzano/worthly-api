<?php

namespace App\Ai\Harness\Retrieval\Adapters;

use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Clients\MercadoLivreClient;
use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use App\Enums\Intent;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ShoppingRetriever implements Retriever
{
    public const CHANNEL = 'shopping';

    public function isEligible(EnrichedQuery $query): bool
    {
        return $query->intent !== Intent::Unknown;
    }

    public function name(): string
    {
        return self::CHANNEL;
    }

    public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array
    {
        $timeout = (int) config('worthly.harness.retrievers.shopping.timeout_ms', $ctx->perAdapterTimeoutMs);
        $maxResults = $ctx->maxItemsPerAdapter;

        $term = $query->productName ?? $query->rawQuery;
        $searchToken = (string) env('SEARCH_TOKEN', '');

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('searchapi')
                ->acceptJson()
                ->timeout(max(1, (int) ceil($timeout / 1000)))
                ->get(SearchApiClient::BASE_URL, [
                    'engine' => 'google_shopping',
                    'q' => $term,
                    'api_key' => $searchToken,
                    'num' => $maxResults,
                ]),
            $pool->as('mercadolivre')
                ->acceptJson()
                ->timeout(max(1, (int) ceil($timeout / 1000)))
                ->get(MercadoLivreClient::BASE_URL.'/sites/MLB/search', [
                    'q' => $term,
                    'limit' => $maxResults,
                ]),
        ]);

        $items = [];

        foreach ($this->normalizeSearchApi($responses['searchapi'] ?? null) as $item) {
            $items[] = $item;
        }

        foreach ($this->normalizeMercadoLivre($responses['mercadolivre'] ?? null) as $item) {
            $items[] = $item;
        }

        $items = $this->dedupByUrl($items);

        usort($items, fn (EvidenceItem $a, EvidenceItem $b) => $b->rawRelevance <=> $a->rawRelevance);

        return $items;
    }

    /**
     * @return list<EvidenceItem>
     */
    protected function normalizeSearchApi(?Response $response): array
    {
        if (! $response instanceof Response) {
            return [];
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results = $data['shopping_results'] ?? $data['organic_results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        $items = [];
        $position = 0;

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = $row['product_link'] ?? $row['link'] ?? $row['url'] ?? null;
            $title = $row['title'] ?? null;

            if (! is_string($url) || ! is_string($title) || $url === '' || $title === '') {
                continue;
            }

            $price = $row['price'] ?? $row['extracted_price'] ?? null;
            $source = $row['source'] ?? $row['seller'] ?? null;

            $items[] = new EvidenceItem(
                sourceChannel: self::CHANNEL,
                url: $url,
                title: $title,
                snippet: trim(sprintf('%s%s%s',
                    is_scalar($price) ? 'Price: '.(string) $price.'. ' : '',
                    is_string($source) ? 'Source: '.$source.'. ' : '',
                    isset($row['snippet']) ? (string) $row['snippet'] : '',
                )),
                publishedAt: null,
                authorityScore: 0.6,
                rawRelevance: $this->rawRelevanceFor($position++, count($results)),
            );
        }

        return $items;
    }

    /**
     * @return list<EvidenceItem>
     */
    protected function normalizeMercadoLivre(?Response $response): array
    {
        if (! $response instanceof Response) {
            return [];
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results = $data['results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        $items = [];
        $position = 0;

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = $row['permalink'] ?? null;
            $title = $row['title'] ?? null;

            if (! is_string($url) || ! is_string($title) || $url === '' || $title === '') {
                continue;
            }

            $price = $row['price'] ?? null;

            $items[] = new EvidenceItem(
                sourceChannel: self::CHANNEL,
                url: $url,
                title: $title,
                snippet: is_scalar($price) ? 'Price: '.(string) $price.' BRL' : '',
                publishedAt: null,
                authorityScore: 0.7,
                rawRelevance: $this->rawRelevanceFor($position++, count($results)),
            );
        }

        return $items;
    }

    protected function rawRelevanceFor(int $position, int $total): float
    {
        $total = max(1, $total);

        return round(1.0 - ($position / max(1, $total + 1)), 4);
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
}
