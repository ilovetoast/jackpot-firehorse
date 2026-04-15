<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_visual_references')) {
            return;
        }

        Schema::create('campaign_visual_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_identity_id')
                ->constrained('collection_campaign_identities')
                ->cascadeOnDelete();

            $table->uuid('asset_id');
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->string('reference_type');
            $table->json('embedding_vector')->nullable();
            $table->float('weight')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['campaign_identity_id', 'reference_type'], 'cvr_identity_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_visual_references');
    }
};
