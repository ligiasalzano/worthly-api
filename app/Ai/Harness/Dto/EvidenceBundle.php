<?php

namespace App\Ai\Harness\Dto;

final readonly class EvidenceBundle
{
    /**
     * @param  list<EvidenceItem>  $items
     */
    public function __construct(
        public array $items,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function idFor(EvidenceItem $item): string
    {
        foreach ($this->items as $index => $candidate) {
            if ($candidate === $item) {
                return 'S'.($index + 1);
            }
        }

        throw new \InvalidArgumentException('EvidenceItem is not part of this bundle.');
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach (array_keys($this->items) as $index) {
            $ids[] = 'S'.($index + 1);
        }

        return $ids;
    }
}
