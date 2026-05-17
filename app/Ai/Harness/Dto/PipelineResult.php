<?php

namespace App\Ai\Harness\Dto;

use Laravel\Ai\Responses\StructuredAgentResponse;

final readonly class PipelineResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public StructuredAgentResponse $response,
        public EvidenceBundle $evidence,
        public string $confidence,
        public bool $degraded,
        public array $metadata = [],
        public ?VerificationReport $verification = null,
    ) {}
}
