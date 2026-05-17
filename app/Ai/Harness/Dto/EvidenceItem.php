<?php

namespace App\Ai\Harness\Dto;

use Carbon\CarbonImmutable;

final readonly class EvidenceItem
{
    public function __construct(
        public string $sourceChannel,
        public string $url,
        public string $title,
        public string $snippet,
        public ?CarbonImmutable $publishedAt,
        public float $authorityScore,
        public float $rawRelevance,
        public ?float $rerankScore = null,
    ) {}
}
