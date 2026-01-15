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
 * Creates per-download aggregation table for download-related activity events.
 * Aggregates events by download group and event_type over time buckets.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('download_event_aggregates', function (Blueprint $table) {
            $table->id();
            
            // Download reference (UUID string, matches activity_events.subject_id when subject_type = Download)
            $table->uuid('download_id')->index();
            $table->foreign('download_id')->references('id')->on('downloads')->onDelete('cascade');
            
            // Tenant scope (for efficient filtering)
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Event type (references EventType enum values)
            $table->string('event_type', 100)->index();
            
            // Time bucket start (bucket_end can be calculated from bucket_start + bucket_size)
            $table->timestamp('bucket_start_at')->index();
            
            // Aggregated count
            $table->unsignedBigInteger('count')->default(0);
            
            // Time bucket end (for consistency with event_aggregates)
            $table->timestamp('bucket_end_at')->nullable()->index();
            
            // AI-ready metadata (stores raw counts and context)
            // Examples: download_type (snapshot/living), source (grid/drawer/collection/public),
            // access_mode, file types in ZIP, zip_size_bytes
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Unique constraint: one aggregate per download/event_type/bucket
            $table->unique(['download_id', 'event_type', 'bucket_start_at'], 'download_event_aggregates_unique');
            
            // Indexes for common queries (custom names to avoid MySQL 64-char limit)
            $table->index(['tenant_id', 'download_id', 'bucket_start_at'], 'dl_agg_tenant_dl_bucket_idx');
            $table->index(['tenant_id', 'event_type', 'bucket_start_at'], 'dl_agg_tenant_event_bucket_idx');
            $table->index(['download_id', 'bucket_start_at'], 'dl_agg_download_bucket_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_event_aggregates');
    }
};
