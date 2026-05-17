<?php

namespace App\Ai\Harness\Rerank\Filters;

use App\Ai\Harness\Dto\EvidenceItem;

class ChannelDiversityFilter
{
    public const REQUIRED_CHANNELS = ['shopping', 'reviews'];

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    public function apply(array $items, int $topK): array
    {
        if ($items === [] || $topK <= 0) {
            return [];
        }

        if (count($items) <= $topK) {
            return array_values($items);
        }

        $top = array_slice($items, 0, $topK);
        $rest = array_slice($items, $topK);

        foreach (self::REQUIRED_CHANNELS as $channel) {
            if ($this->channelPresent($top, $channel)) {
                continue;
            }

            $promoteIndex = $this->findChannelIndex($rest, $channel);

            if ($promoteIndex === null) {
                continue;
            }

            $replaceIndex = $this->findReplaceIndex($top);

            if ($replaceIndex === null) {
                continue;
            }

            $top[$replaceIndex] = $rest[$promoteIndex];
            array_splice($rest, $promoteIndex, 1);
        }

        return array_values($top);
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    protected function channelPresent(array $items, string $channel): bool
    {
        foreach ($items as $item) {
            if ($item->sourceChannel === $channel) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    protected function findChannelIndex(array $items, string $channel): ?int
    {
        foreach ($items as $idx => $item) {
            if ($item->sourceChannel === $channel) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Pick the lowest-ranked item in the top window whose channel has at least one
     * other representative in the top window, so we never collapse another channel
     * to zero while promoting a required one.
     *
     * @param  list<EvidenceItem>  $top
     */
    protected function findReplaceIndex(array $top): ?int
    {
        $counts = [];
        foreach ($top as $item) {
            $counts[$item->sourceChannel] = ($counts[$item->sourceChannel] ?? 0) + 1;
        }

        for ($i = count($top) - 1; $i >= 0; $i--) {
            if (($counts[$top[$i]->sourceChannel] ?? 0) > 1) {
                return $i;
            }
        }

        return count($top) - 1;
    }
}
