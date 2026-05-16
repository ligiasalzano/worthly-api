<?php

namespace App\Http\Resources;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Analysis
 */
class AnalysisListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->product_name,
            'input_type' => $this->inputType?->slug,
            'recommendation' => [
                'decision' => $this->recommendationDecision?->slug,
                'reason' => $this->recommendation_reason,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
