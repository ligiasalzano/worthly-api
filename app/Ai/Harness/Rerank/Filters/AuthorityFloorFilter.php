<?php

namespace App\Ai\Harness\Rerank\Filters;

use App\Ai\Harness\Dto\EvidenceItem;

class AuthorityFloorFilter
{
    public const FLOOR = 0.3;

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    public function apply(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $byChannel = [];
        foreach ($items as $item) {
            $byChannel[$item->sourceChannel] = ($byChannel[$item->sourceChannel] ?? 0) + 1;
        }

        $kept = [];
        foreach ($items as $item) {
            if ($item->authorityScore >= self::FLOOR) {
                $kept[] = $item;

                continue;
            }

            if (($byChannel[$item->sourceChannel] ?? 0) === 1) {
                $kept[] = $item;
            }
        }

        return array_values($kept);
    }
}
