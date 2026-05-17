<?php

namespace App\Ai\Harness\Rerank\Filters;

use App\Ai\Harness\Dto\EvidenceItem;
use Carbon\CarbonImmutable;

class RecencyDecayFilter
{
    public const SHOPPING_MAX_AGE_DAYS = 60;

    public const SHOPPING_CHANNEL = 'shopping';

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    public function apply(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $cutoff = CarbonImmutable::now()->subDays(self::SHOPPING_MAX_AGE_DAYS);
        $kept = [];

        foreach ($items as $item) {
            if ($item->sourceChannel !== self::SHOPPING_CHANNEL) {
                $kept[] = $item;

                continue;
            }

            if ($item->publishedAt === null) {
                $kept[] = $item;

                continue;
            }

            if ($item->publishedAt->greaterThanOrEqualTo($cutoff)) {
                $kept[] = $item;
            }
        }

        return array_values($kept);
    }
}
