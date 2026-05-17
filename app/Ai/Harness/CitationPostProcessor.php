<?php

namespace App\Ai\Harness;

use App\Ai\Harness\Dto\EvidenceBundle;

class CitationPostProcessor
{
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can',
        'could', 'did', 'do', 'does', 'for', 'from', 'had', 'has', 'have', 'in',
        'is', 'it', 'its', 'might', 'must', 'of', 'on', 'or', 'should', 'so',
        'such', 'than', 'that', 'the', 'their', 'them', 'these', 'they', 'this',
        'those', 'to', 'was', 'were', 'will', 'with', 'would',
        'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'de', 'da', 'do',
        'das', 'dos', 'em', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com',
        'sem', 'se', 'que', 'mais', 'mas', 'ou', 'foi', 'ser', 'tem', 'são',
    ];

    private const MIN_TERM_LENGTH = 4;

    /**
     * Strip citations that reference unknown evidence IDs or whose snippet
     * shares no significant term with the cited claim. When any citation is
     * stripped, downgrade `confidence` to `low`.
     *
     * @param  array<string, mixed>  $structuredOutput
     * @return array{output: array<string, mixed>, stripped: bool}
     */
    public function process(array $structuredOutput, EvidenceBundle $bundle): array
    {
        if (! isset($structuredOutput['sources_used']) || ! is_array($structuredOutput['sources_used'])) {
            return ['output' => $structuredOutput, 'stripped' => false];
        }

        $bundleIds = $bundle->ids();
        $snippetsById = $this->snippetsById($bundle);

        $stripped = false;
        $cleanedEntries = [];

        foreach ($structuredOutput['sources_used'] as $entry) {
            if (! is_array($entry)) {
                $stripped = true;

                continue;
            }

            $field = isset($entry['field']) ? (string) $entry['field'] : '';
            $rawIds = $entry['evidence_ids'] ?? [];

            if (! is_array($rawIds)) {
                $stripped = true;

                continue;
            }

            $claimTerms = $this->significantTerms($this->claimTextFor($structuredOutput, $field));

            $validIds = [];

            foreach ($rawIds as $id) {
                $normalized = $this->normalizeId($id);

                if ($normalized === null || ! in_array($normalized, $bundleIds, true)) {
                    $stripped = true;

                    continue;
                }

                $snippet = $snippetsById[$normalized] ?? '';

                if (! $this->snippetSharesTerm($snippet, $claimTerms)) {
                    $stripped = true;

                    continue;
                }

                $validIds[] = $normalized;
            }

            if ($validIds === []) {
                $stripped = true;

                continue;
            }

            $cleanedEntries[] = [
                'field' => $field,
                'evidence_ids' => array_values(array_unique($validIds)),
            ];
        }

        $structuredOutput['sources_used'] = $cleanedEntries;

        if ($stripped) {
            $structuredOutput['confidence'] = 'low';
        }

        return ['output' => $structuredOutput, 'stripped' => $stripped];
    }

    /**
     * @return array<string, string>
     */
    private function snippetsById(EvidenceBundle $bundle): array
    {
        $map = [];

        foreach ($bundle->items as $index => $item) {
            $map['S'.($index + 1)] = $item->title.' '.$item->snippet;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function claimTextFor(array $output, string $field): string
    {
        return match ($field) {
            'product' => $this->joinTextValues($output['product'] ?? []),
            'summary' => (string) ($output['summary'] ?? ''),
            'cost_benefit', 'cost_benefit_analysis' => (string) ($output['cost_benefit_analysis'] ?? ''),
            'similar_products' => $this->joinTextValues($output['similar_products'] ?? []),
            'recommendation' => $this->joinTextValues($output['recommendation'] ?? []),
            default => '',
        };
    }

    private function joinTextValues(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        array_walk_recursive($value, function ($v) use (&$parts) {
            if (is_string($v)) {
                $parts[] = $v;
            }
        });

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function significantTerms(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < self::MIN_TERM_LENGTH) {
                continue;
            }

            if (in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            $terms[$token] = true;
        }

        return array_keys($terms);
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippetSharesTerm(string $snippet, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        $haystack = mb_strtolower($snippet, 'UTF-8');

        foreach ($terms as $term) {
            if (mb_strpos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeId(mixed $id): ?string
    {
        if (is_int($id)) {
            return 'S'.$id;
        }

        if (! is_string($id)) {
            return null;
        }

        $trimmed = trim($id);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return 'S'.$trimmed;
        }

        return $trimmed;
    }
}
