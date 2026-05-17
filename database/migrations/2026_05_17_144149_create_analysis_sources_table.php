<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('source_channel', 32);
            $table->text('url');
            $table->string('title', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->float('authority_score')->default(0.0);
            $table->float('rerank_score')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['analysis_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_sources');
    }
};
