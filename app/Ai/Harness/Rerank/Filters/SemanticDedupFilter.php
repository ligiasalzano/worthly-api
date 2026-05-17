<?php

namespace App\Ai\Harness\Rerank\Filters;

use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\EmbeddingClient;

class SemanticDedupFilter
{
    public const COSINE_THRESHOLD = 0.92;

    public function __construct(protected EmbeddingClient $embeddings) {}

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    public function apply(array $items): array
    {
        if (count($items) < 2) {
            return array_values($items);
        }

        $vectors = [];
        foreach ($items as $idx => $item) {
            $vectors[$idx] = $this->embeddings->embed($item->snippet);
        }

        $kept = [];
        $keptVectors = [];

        foreach ($items as $idx => $item) {
            $itemVector = $vectors[$idx];
            $dupOfKey = null;

            foreach ($kept as $keptKey => $keptItem) {
                $cosine = $this->cosine($itemVector, $keptVectors[$keptKey]);

                if ($cosine > self::COSINE_THRESHOLD) {
                    $dupOfKey = $keptKey;
                    break;
                }
            }

            if ($dupOfKey === null) {
                $kept[$idx] = $item;
                $keptVectors[$idx] = $itemVector;

                continue;
            }

            if ($item->authorityScore > $kept[$dupOfKey]->authorityScore) {
                unset($kept[$dupOfKey], $keptVectors[$dupOfKey]);
                $kept[$idx] = $item;
                $keptVectors[$idx] = $itemVector;
            }
        }

        return array_values($kept);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    protected function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));

        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
