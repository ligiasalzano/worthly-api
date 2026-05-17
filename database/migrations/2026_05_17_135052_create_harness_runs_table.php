<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->nullable()->constrained('analyses')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('total_ms')->nullable();
            $table->integer('llm_calls')->default(0);
            $table->integer('retrieval_calls')->default(0);
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->boolean('degraded')->default(false);
            $table->boolean('budget_exhausted')->default(false);
            $table->text('error')->nullable();
            $table->jsonb('layers')->nullable();
            $table->timestamps();

            $table->index('analysis_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harness_runs');
    }
};
