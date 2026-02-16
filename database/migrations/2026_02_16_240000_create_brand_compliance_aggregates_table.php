<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8: Brand compliance aggregates for execution alignment overview.
     * One row per brand. Populated by RescoreBrandExecutionsJob.
     */
    public function up(): void
    {
        Schema::create('brand_compliance_aggregates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('avg_score', 5, 2)->default(0);
            $table->unsignedInteger('execution_count')->default(0);
            $table->unsignedInteger('high_score_count')->default(0);
            $table->unsignedInteger('low_score_count')->default(0);
            $table->timestamp('last_scored_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_compliance_aggregates');
    }
};
