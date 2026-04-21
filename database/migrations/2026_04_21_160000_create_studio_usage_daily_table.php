<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio (composition editor) telemetry — one row per tenant × calendar day × metric.
 *
 * Used for avg session time (sum_duration_ms / event_count), batch sizes, and
 * optional cost attribution later. Does not store per-save rows (avoids DB blow-up).
 *
 * Metrics (see App\Services\StudioUsageService::METRICS):
 *   composition_create, composition_batch, composition_manual_checkpoint
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->string('metric', 64);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedBigInteger('sum_duration_ms')->default(0);
            $table->decimal('sum_cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'usage_date', 'metric'], 'studio_usage_daily_tenant_date_metric_unique');
            $table->index(['usage_date', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_usage_daily');
    }
};
