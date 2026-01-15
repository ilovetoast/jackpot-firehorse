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
        Schema::create('asset_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('cascade');
            $table->uuid('asset_id');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            // Metric classification
            $table->string('metric_type', 50)->index(); // download, view, etc.
            $table->string('view_type', 50)->nullable(); // drawer, large_view (for view metrics)
            
            // Request context for analytics
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // Extensible metadata for future metric types
            $table->json('metadata')->nullable();
            
            // Timestamp (append-only, no updated_at)
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for common queries
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'brand_id', 'created_at']);
            $table->index(['asset_id', 'metric_type', 'created_at']);
            $table->index(['asset_id', 'user_id', 'metric_type', 'created_at']);
            $table->index(['metric_type', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_metrics');
    }
};
