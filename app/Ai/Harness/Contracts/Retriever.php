<?php

namespace App\Ai\Harness\Contracts;

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\RetrievalContext;

interface Retriever
{
    public function name(): string;

    /**
     * @return list<EvidenceItem>
     */
    public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array;

    public function isEligible(EnrichedQuery $query): bool;
}
