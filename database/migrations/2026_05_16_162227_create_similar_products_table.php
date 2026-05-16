<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('similar_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('reason');
            $table->string('price_reference', 255)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['analysis_id', 'sort_order'], 'similar_products_analysis_id_sort_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('similar_products');
    }
};
