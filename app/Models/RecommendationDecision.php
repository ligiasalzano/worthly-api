<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationDecision extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'bool',
        'sort_order' => 'int',
    ];

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }
}
