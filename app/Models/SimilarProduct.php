<?php

namespace App\Models;

use Database\Factories\SimilarProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimilarProduct extends Model
{
    /** @use HasFactory<SimilarProductFactory> */
    use HasFactory;

    protected $fillable = [
        'analysis_id',
        'name',
        'reason',
        'price_reference',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'int',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
