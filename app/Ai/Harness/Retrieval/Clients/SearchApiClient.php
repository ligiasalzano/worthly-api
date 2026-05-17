<?php

namespace App\Ai\Harness\Retrieval\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SearchApiClient
{
    public const BASE_URL = 'https://www.searchapi.io/api/v1/search';

    /**
     * @return list<array{title: string, url: string, price: ?string, source: ?string}>
     */
    public function shoppingSearch(
        string $query,
        ?string $location = null,
        int $maxResults = 8,
        ?int $timeoutMs = null,
    ): array {
        $params = [
            'engine' => 'google_shopping',
            'q' => $query,
            'api_key' => $this->token(),
            'num' => $maxResults,
        ];

        if ($location !== null) {
            $params['location'] = $location;
        }

        $request = Http::acceptJson();

        if ($timeoutMs !== null) {
            $request = $request->timeout(max(1, (int) ceil($timeoutMs / 1000)));
        }

        $response = $request->get(self::BASE_URL, $params);

        return $this->normalize($response);
    }

    protected function token(): string
    {
        return (string) env('SEARCH_TOKEN', '');
    }

    /**
     * @return list<array{title: string, url: string, price: ?string, source: ?string}>
     */
    protected function normalize(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results = $data['shopping_results'] ?? $data['organic_results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        $rows = [];

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $item['product_link'] ?? $item['link'] ?? $item['url'] ?? null;
            $title = $item['title'] ?? null;

            if (! is_string($url) || ! is_string($title) || $url === '' || $title === '') {
                continue;
            }

            $rows[] = [
                'title' => $title,
                'url' => $url,
                'price' => isset($item['price']) ? (string) $item['price'] : (isset($item['extracted_price']) ? (string) $item['extracted_price'] : null),
                'source' => isset($item['source']) ? (string) $item['source'] : (isset($item['seller']) ? (string) $item['seller'] : null),
            ];
        }

        return $rows;
    }
}
