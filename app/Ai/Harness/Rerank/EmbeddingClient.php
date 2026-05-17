<?php

namespace App\Ai\Harness\Rerank;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EmbeddingClient
{
    public const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    public const DEFAULT_MODEL = 'text-embedding-3-small';

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $key = $this->cacheKey($text);
        $ttl = (int) config('worthly.harness.cache.embedding_ttl', 60 * 60 * 24 * 30);

        if ($ttl <= 0) {
            return $this->fetch($text);
        }

        return Cache::remember($key, $ttl, fn () => $this->fetch($text));
    }

    public function cacheKey(string $text): string
    {
        return 'worthly:e:'.sha1($text);
    }

    /**
     * @return list<float>
     */
    protected function fetch(string $text): array
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
        $model = (string) config('worthly.harness.embedding.model', self::DEFAULT_MODEL);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(8)
            ->post(self::ENDPOINT, [
                'model' => $model,
                'input' => $text,
            ]);

        $payload = $response->json();

        $vector = $payload['data'][0]['embedding'] ?? null;

        if (! is_array($vector)) {
            return [];
        }

        return array_values(array_map(fn ($v) => (float) $v, $vector));
    }
}
