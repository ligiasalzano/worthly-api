<?php

namespace App\Ai\Harness\Dto;

use App\Enums\Intent;

final readonly class EnrichedQuery
{
    /**
     * @param  list<string>  $subQueries
     * @param  list<string>  $hydePassages
     */
    public function __construct(
        public string $rawQuery,
        public ?string $productName,
        public ?string $brand,
        public ?string $category,
        public ?string $region,
        public ?string $useCase,
        public ?string $budgetHint,
        public Intent $intent,
        public array $subQueries = [],
        public array $hydePassages = [],
    ) {}

    public static function fromRawQuery(string $rawQuery, ?string $region = null): self
    {
        return new self(
            rawQuery: $rawQuery,
            productName: null,
            brand: null,
            category: null,
            region: $region,
            useCase: null,
            budgetHint: null,
            intent: Intent::Unknown,
            subQueries: [],
            hydePassages: [],
        );
    }
}
