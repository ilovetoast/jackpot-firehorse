<?php

/**
 * Phase 3.1 — Downloader Foundations
 * ZIP generation, delivery, and cleanup are NOT implemented here.
 * 
 * This migration creates the downloads table for Download Groups.
 * Download Groups support snapshot (immutable) and living (mutable) downloads.
 */

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
        Schema::create('downloads', function (Blueprint $table) {
            // Primary key - UUID
            $table->uuid('id')->primary();

            // Tenant relationship
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // User who created the download (nullable for system-initiated)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');

            // Download type and source
            $table->string('download_type'); // 'snapshot' | 'living'
            $table->string('source'); // 'grid' | 'drawer' | 'collection' | 'public' | 'admin'

            // Metadata
            $table->string('title')->nullable(); // Renamable on top tiers
            $table->string('slug'); // Stable public ID for hosted pages (unique per tenant)
            $table->unsignedInteger('version')->default(1); // Increments on invalidation/regeneration

            // Status tracking
            $table->string('status'); // 'pending' | 'ready' | 'invalidated' | 'failed'
            $table->string('zip_status')->default('none'); // 'none' | 'building' | 'ready' | 'invalidated' | 'failed'

            // ZIP file information (S3 key only, not full URL)
            $table->string('zip_path')->nullable(); // S3 key for ZIP file
            $table->unsignedBigInteger('zip_size_bytes')->nullable(); // ZIP file size in bytes

            // Lifecycle management
            $table->timestamp('expires_at')->nullable(); // When download expires (plan-based)
            $table->timestamp('deleted_at')->nullable(); // Soft delete timestamp
            $table->timestamp('hard_delete_at')->nullable(); // When hard delete should occur (plan-based)

            // Configuration and permissions
            $table->json('download_options')->nullable(); // Additional options (format preferences, etc.)
            $table->string('access_mode')->default('team'); // 'public' | 'team' | 'restricted'
            $table->boolean('allow_reshare')->default(true); // Whether download can be reshared

            // Timestamps
            $table->timestamps();

            // Foreign key indexes
            $table->index('tenant_id');
            $table->index('created_by_user_id');
            $table->index('deleted_at');
            $table->index('hard_delete_at');

            // Status indexes for queries
            $table->index('status');
            $table->index('zip_status');
            $table->index('download_type');
            
            // Unique constraint: slug must be unique per tenant
            $table->unique(['tenant_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
