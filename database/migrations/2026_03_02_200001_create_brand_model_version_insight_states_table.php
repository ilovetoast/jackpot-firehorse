<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_model_version_insight_states')) {
            return;
        }
        Schema::create('brand_model_version_insight_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_model_version_id');
            $table->foreign('brand_model_version_id', 'bmv_insight_states_version_fk')
                ->references('id')
                ->on('brand_model_versions')
                ->cascadeOnDelete();
            $table->foreignId('source_snapshot_id')->nullable();
            $table->foreign('source_snapshot_id', 'bmv_insight_states_snapshot_fk')
                ->references('id')
                ->on('brand_research_snapshots')
                ->nullOnDelete();
            $table->json('dismissed')->nullable();
            $table->json('accepted')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            $table->unique('brand_model_version_id', 'bmv_insight_states_version_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_model_version_insight_states');
    }
};
