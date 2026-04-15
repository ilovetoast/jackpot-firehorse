<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_alignment_scores', function (Blueprint $table) {
            $table->id();

            $table->uuid('asset_id');
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->foreignId('collection_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('campaign_identity_id')
                ->constrained('collection_campaign_identities')
                ->cascadeOnDelete();

            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->integer('overall_score')->nullable();
            $table->float('confidence')->nullable();
            $table->string('level')->nullable();
            $table->json('breakdown_json')->nullable();
            $table->string('engine_version')->nullable();
            $table->boolean('ai_used')->default(false);

            $table->timestamps();

            $table->unique(['asset_id', 'collection_id', 'engine_version'], 'campaign_score_asset_collection_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_alignment_scores');
    }
};
