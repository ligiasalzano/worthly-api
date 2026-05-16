<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('input_type_id')->constrained('input_types')->restrictOnDelete();
            $table->foreignId('recommendation_decision_id')->constrained('recommendation_decisions')->restrictOnDelete();

            $table->text('query')->nullable();
            $table->string('image_path', 2048)->nullable();

            $table->string('product_name', 255);
            $table->string('product_category', 255)->nullable();
            $table->string('estimated_price_range', 255)->nullable();

            $table->text('summary')->nullable();
            $table->text('cost_benefit_analysis')->nullable();
            $table->text('recommendation_reason')->nullable();

            $table->jsonb('raw_response')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'analyses_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
