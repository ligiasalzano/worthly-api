<?php

use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recommendation_decisions')->updateOrInsert(
            ['slug' => RecommendationDecisionEnum::InsufficientEvidence->value],
            [
                'name' => 'Insufficient evidence',
                'description' => 'Not enough evidence to make a recommendation',
                'sort_order' => 6,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('recommendation_decisions')
            ->where('slug', RecommendationDecisionEnum::InsufficientEvidence->value)
            ->delete();
    }
};
