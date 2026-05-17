<?php

namespace App\Ai\Harness\Contracts;

use App\Ai\Harness\Dto\EvidenceBundle;
use App\Models\Analysis;

interface CitationStore
{
    public function persist(Analysis $analysis, EvidenceBundle $bundle): void;
}
