<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brand Compliance Scores — deterministic scoring against Brand DNA rules.
     * One row per brand + asset. Upserted on metadata update / AI tagging completion.
     */
    public function up(): void
    {
        Schema::create('brand_compliance_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->uuid('asset_id')
                ->index();

            $table->unsignedTinyInteger('overall_score')->default(0);
            $table->unsignedTinyInteger('color_score')->default(0);
            $table->unsignedTinyInteger('typography_score')->default(0);
            $table->unsignedTinyInteger('tone_score')->default(0);
            $table->unsignedTinyInteger('imagery_score')->default(0);

            $table->json('breakdown_payload')->nullable();

            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->unique(['brand_id', 'asset_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_compliance_scores');
    }
};
