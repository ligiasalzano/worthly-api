<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InputType extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }
}
