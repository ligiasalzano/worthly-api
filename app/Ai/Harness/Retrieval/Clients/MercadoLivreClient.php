<?php

namespace App\Ai\Harness\Retrieval\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MercadoLivreClient
{
    public const BASE_URL = 'https://api.mercadolibre.com';

    /**
     * @return list<array{title: string, url: string, price: ?string, source: ?string}>
     */
    public function search(
        string $query,
        int $maxResults = 8,
        ?int $timeoutMs = null,
    ): array {
        $request = Http::acceptJson();

        if ($timeoutMs !== null) {
            $request = $request->timeout(max(1, (int) ceil($timeoutMs / 1000)));
        }

        $response = $request->get(self::BASE_URL.'/sites/MLB/search', [
            'q' => $query,
            'limit' => $maxResults,
        ]);

        return $this->normalize($response);
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

        $results = $data['results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        $rows = [];

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $item['permalink'] ?? null;
            $title = $item['title'] ?? null;

            if (! is_string($url) || ! is_string($title) || $url === '' || $title === '') {
                continue;
            }

            $rows[] = [
                'title' => $title,
                'url' => $url,
                'price' => isset($item['price']) ? (string) $item['price'] : null,
                'source' => 'mercadolivre',
            ];
        }

        return $rows;
    }
}
