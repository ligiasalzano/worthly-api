<?php

namespace App\Ai\Harness\Rerank;

use App\Ai\Harness\Contracts\Reranker;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CohereReranker implements Reranker
{
    public const ENDPOINT = 'https://api.cohere.com/v2/rerank';

    protected bool $degraded = false;

    public function rerank(EnrichedQuery $query, array $items, int $topK): array
    {
        $this->degraded = false;

        if ($items === [] || $topK <= 0) {
            return [];
        }

        $documents = array_map(fn (EvidenceItem $item) => $item->snippet, $items);
        $model = (string) config('worthly.harness.rerank.model', 'rerank-v3.5');
        $apiKey = (string) env('COHERE_API_KEY', '');

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(8)
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'query' => $this->queryText($query),
                    'documents' => $documents,
                    'top_n' => count($items),
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Cohere rerank returned HTTP '.$response->status());
            }

            $payload = $response->json();
            $results = is_array($payload) ? ($payload['results'] ?? []) : [];

            if (! is_array($results)) {
                throw new \RuntimeException('Cohere rerank payload missing results array.');
            }

            $reordered = $this->applyResults($items, $results);

            return array_values(array_slice($reordered, 0, $topK));
        } catch (Throwable $e) {
            Log::warning('Cohere rerank failed, falling back to NullReranker.', [
                'error' => $e->getMessage(),
            ]);

            $this->degraded = true;

            return (new NullReranker)->rerank($query, $items, $topK);
        }
    }

    public function wasDegraded(): bool
    {
        return $this->degraded;
    }

    protected function queryText(EnrichedQuery $query): string
    {
        return $query->productName !== null && $query->productName !== ''
            ? $query->productName
            : $query->rawQuery;
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @param  array<int, mixed>  $results
     * @return list<EvidenceItem>
     */
    protected function applyResults(array $items, array $results): array
    {
        $pairs = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $index = $row['index'] ?? null;
            $score = $row['relevance_score'] ?? null;

            if (! is_int($index) || ! array_key_exists($index, $items) || ! is_numeric($score)) {
                continue;
            }

            $pairs[] = ['index' => $index, 'score' => (float) $score];
        }

        usort($pairs, function (array $a, array $b) {
            return $b['score'] <=> $a['score']
                ?: $a['index'] <=> $b['index'];
        });

        $reordered = [];

        foreach ($pairs as $pair) {
            $item = $items[$pair['index']];

            $reordered[] = new EvidenceItem(
                sourceChannel: $item->sourceChannel,
                url: $item->url,
                title: $item->title,
                snippet: $item->snippet,
                publishedAt: $item->publishedAt,
                authorityScore: $item->authorityScore,
                rawRelevance: $item->rawRelevance,
                rerankScore: $pair['score'],
            );
        }

        return $reordered;
    }
}
