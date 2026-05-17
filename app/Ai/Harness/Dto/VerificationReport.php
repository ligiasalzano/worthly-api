<?php

namespace App\Ai\Harness\Dto;

final readonly class VerificationReport
{
    /**
     * @param  list<array{field: string, status: string, evidence_ids: list<string>}>  $claims
     */
    public function __construct(
        public array $claims,
        public bool $anyUnsupported,
    ) {}
}
