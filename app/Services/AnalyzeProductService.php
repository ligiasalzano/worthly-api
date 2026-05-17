<?php

namespace App\Services;

use App\Ai\Harness\AnalysisPipeline;
use App\Models\Analysis;
use App\Models\User;

class AnalyzeProductService
{
    public function __construct(private AnalysisPipeline $pipeline) {}

    public function analyzeText(User $user, string $query): Analysis
    {
        return $this->pipeline->analyzeText($user, $query);
    }

    public function analyzeImage(User $user, string $imagePath): Analysis
    {
        return $this->pipeline->analyzeImage($user, $imagePath);
    }
}
