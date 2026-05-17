<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarnessRun extends Model
{
    protected $fillable = [
        'analysis_id',
        'started_at',
        'finished_at',
        'total_ms',
        'llm_calls',
        'retrieval_calls',
        'tokens_in',
        'tokens_out',
        'cache_hit',
        'degraded',
        'budget_exhausted',
        'error',
        'layers',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'total_ms' => 'int',
        'llm_calls' => 'int',
        'retrieval_calls' => 'int',
        'tokens_in' => 'int',
        'tokens_out' => 'int',
        'cache_hit' => 'bool',
        'degraded' => 'bool',
        'budget_exhausted' => 'bool',
        'layers' => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
