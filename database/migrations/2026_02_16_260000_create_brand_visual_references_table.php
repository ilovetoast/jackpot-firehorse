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
        if (Schema::hasTable('brand_visual_references')) {
            return;
        }

        Schema::create('brand_visual_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->onDelete('cascade');
            $table->uuid('asset_id')->nullable();
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('set null');
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
