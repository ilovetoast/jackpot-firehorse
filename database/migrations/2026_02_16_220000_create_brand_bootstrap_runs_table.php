<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brand Bootstrap Runs — foundation for URL-based Brand DNA extraction.
     * Scoped to brand. No scraping/AI yet.
     */
    public function up(): void
    {
        Schema::create('brand_bootstrap_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending | running | completed | failed

            $table->string('source_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('ai_output_payload')->nullable();

            $table->foreignId('approved_version_id')
                ->nullable()
                ->constrained('brand_model_versions')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

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
        Schema::dropIfExists('brand_bootstrap_runs');
    }
};
