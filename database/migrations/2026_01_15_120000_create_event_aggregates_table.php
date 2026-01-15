<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Analytics Aggregation (FOUNDATION)
 * 
 * Consumes events from locked phases only.
 * Must not modify event producers.
 * 
 * Creates time-bucketed aggregation table for activity events.
 * Aggregates events by tenant, event_type, and time bucket.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_aggregates', function (Blueprint $table) {
            $table->id();
            
            // Tenant scope
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('set null');
            
            // Event type (references EventType enum values)
            $table->string('event_type', 100)->index();
            
            // Time bucket boundaries (inclusive)
            $table->timestamp('bucket_start_at')->index();
            $table->timestamp('bucket_end_at')->index();
            
            // Aggregated counts
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedBigInteger('success_count')->default(0);
            $table->unsignedBigInteger('failure_count')->default(0);
            
            // AI-ready metadata (stores raw counts and context)
            // Examples: error codes, file types, download types, sources
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Unique constraint: one aggregate per tenant/event_type/bucket
            // bucket_end_at is derivable from bucket_start_at + bucket_size, so not in unique constraint
            $table->unique(['tenant_id', 'event_type', 'bucket_start_at'], 'event_aggregates_unique');
            
            // Indexes for common queries
            $table->index(['tenant_id', 'event_type', 'bucket_start_at']);
            $table->index(['tenant_id', 'bucket_start_at']);
            $table->index(['event_type', 'bucket_start_at']);
            // Note: bucket_start_at already has an index from line 34 above
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_aggregates');
    }
};
