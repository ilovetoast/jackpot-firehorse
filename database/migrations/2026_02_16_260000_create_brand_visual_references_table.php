<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive: brand_visual_references for imagery similarity scoring.
     * Stores embedding vectors for logo and photography reference images.
     */
    public function up(): void
    {
        Schema::create('brand_visual_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->onDelete('cascade');
            $table->foreignId('asset_id')->nullable()->constrained()->onDelete('set null');
            $table->json('embedding_vector')->nullable();
            $table->string('type', 50)->index(); // logo | photography_reference
            $table->timestamps();

            $table->index(['brand_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_visual_references');
    }
};
