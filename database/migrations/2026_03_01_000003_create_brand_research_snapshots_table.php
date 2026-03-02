<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('brand_research_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('brand_model_version_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('source_url');
            $table->string('status')->default('pending'); // pending|running|completed|failed

            $table->json('snapshot')->nullable(); // logo_url, primary_colors[], detected_fonts[], hero_headlines[], brand_bio, etc

            $table->timestamps();

            $table->index('brand_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_research_snapshots');
    }
};
