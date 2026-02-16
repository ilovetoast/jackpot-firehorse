<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive: asset_embeddings for imagery similarity scoring.
     * Stores embedding vectors per asset for comparison against brand visual references.
     */
    public function up(): void
    {
        Schema::create('asset_embeddings', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id')->unique();
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->json('embedding_vector');
            $table->string('model', 100);
            $table->timestamps();

            $table->index('asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_embeddings');
    }
};
