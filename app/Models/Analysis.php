<?php

namespace App\Models;

use App\Enums\InputType as InputTypeEnum;
use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'input_type_id',
        'recommendation_decision_id',
        'query',
        'image_path',
        'product_name',
        'product_category',
        'estimated_price_range',
        'summary',
        'cost_benefit_analysis',
        'recommendation_reason',
        'raw_response',
        'confidence',
        'degraded',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'degraded' => 'bool',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inputType(): BelongsTo
    {
        return $this->belongsTo(InputType::class);
    }

    public function recommendationDecision(): BelongsTo
    {
        return $this->belongsTo(RecommendationDecision::class);
    }

    public function similarProducts(): HasMany
    {
        return $this->hasMany(SimilarProduct::class)->orderBy('sort_order');
    }

    public function inputTypeEnum(): InputTypeEnum
    {
        return InputTypeEnum::from($this->inputType->slug);
    }

    public function recommendationDecisionEnum(): RecommendationDecisionEnum
    {
        return RecommendationDecisionEnum::from($this->recommendationDecision->slug);
    }
}
