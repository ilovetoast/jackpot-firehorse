<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_pipeline_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_pipeline_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->json('suggestions')->nullable();
            $table->json('coherence')->nullable();
            $table->json('alignment')->nullable();
            $table->json('report')->nullable();
            $table->json('sections_json')->nullable();
            $table->string('status', 32)->default('pending'); // pending, running, completed, failed
            $table->string('source_url')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'brand_model_version_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_pipeline_snapshots');
    }
};
