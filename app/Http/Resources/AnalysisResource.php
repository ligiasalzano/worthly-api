<?php

namespace App\Http\Resources;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Analysis
 */
class AnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inputTypeSlug = $this->inputType?->slug;

        return [
            'id' => $this->id,
            'product' => [
                'name' => $this->product_name,
                'category' => $this->product_category,
                'estimated_price_range' => $this->estimated_price_range,
            ],
            'summary' => $this->summary,
            'similar_products' => $this->similarProducts->map(fn ($similar) => [
                'name' => $similar->name,
                'reason' => $similar->reason,
                'price_reference' => $similar->price_reference,
            ])->all(),
            'cost_benefit_analysis' => $this->cost_benefit_analysis,
            'recommendation' => [
                'decision' => $this->recommendationDecision?->slug,
                'reason' => $this->recommendation_reason,
            ],
            'input_type' => $inputTypeSlug,
            'image_url' => $this->image_path
                ? route('analyses.image', ['analysis' => $this->id])
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
