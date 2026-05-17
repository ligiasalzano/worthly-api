<?php

namespace App\Ai\Harness\Retrieval\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TavilyClient
{
    public const BASE_URL = 'https://api.tavily.com';

    /**
     * @param  list<string>  $includeDomains
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        array $includeDomains = [],
        int $maxResults = 8,
        ?int $timeoutMs = null,
    ): array {
        $payload = [
            'query' => $query,
            'max_results' => $maxResults,
            'search_depth' => 'basic',
        ];

        if ($includeDomains !== []) {
            $payload['include_domains'] = $includeDomains;
        }

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token(),
            'Content-Type' => 'application/json',
        ])->acceptJson();

        if ($timeoutMs !== null) {
            $request = $request->timeout(max(1, (int) ceil($timeoutMs / 1000)));
        }

        $response = $request->post(self::BASE_URL.'/search', $payload);

        return $this->decode($response);
    }

    protected function token(): string
    {
        return (string) env('TAVILY_TOKEN', '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
