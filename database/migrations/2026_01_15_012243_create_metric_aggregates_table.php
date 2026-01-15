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
        if (Schema::hasTable('metric_aggregates')) {
            return;
        }

        Schema::create('metric_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('cascade');
            $table->uuid('asset_id');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            
            // Metric classification and period
            $table->string('metric_type', 50); // download, view, etc.
            $table->string('period', 20)->index(); // daily, weekly, monthly
            $table->date('period_start')->index(); // Start date of the period
            
            // Aggregated counts
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedInteger('unique_users')->default(0); // Count of distinct users
            
            $table->timestamps();
            
            // Unique constraint: one aggregate per asset/metric_type/period/period_start
            $table->unique(['asset_id', 'metric_type', 'period', 'period_start'], 'metric_aggregate_unique');
            
            // Indexes for common queries (using custom names to avoid MySQL 64-char limit)
            $table->index(['tenant_id', 'metric_type', 'period_start'], 'ma_tenant_metric_period_idx');
            $table->index(['tenant_id', 'brand_id', 'metric_type', 'period_start'], 'ma_tenant_brand_metric_period_idx');
            $table->index(['asset_id', 'metric_type', 'period', 'period_start'], 'ma_asset_metric_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_aggregates');
    }
};
