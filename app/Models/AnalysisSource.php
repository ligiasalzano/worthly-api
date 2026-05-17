<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisSource extends Model
{
    protected $fillable = [
        'analysis_id',
        'position',
        'source_channel',
        'url',
        'title',
        'snippet',
        'authority_score',
        'rerank_score',
        'published_at',
    ];

    protected $casts = [
        'position' => 'int',
        'authority_score' => 'float',
        'rerank_score' => 'float',
        'published_at' => 'immutable_datetime',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
