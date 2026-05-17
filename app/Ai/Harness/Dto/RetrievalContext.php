<?php

namespace App\Ai\Harness\Dto;

final readonly class RetrievalContext
{
    public function __construct(
        public ?string $region = null,
        public int $maxItemsPerAdapter = 8,
        public int $perAdapterTimeoutMs = 4000,
    ) {}
}
