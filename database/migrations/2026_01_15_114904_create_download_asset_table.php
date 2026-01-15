<?php

/**
 * Phase 3.1 — Downloader Foundations
 * ZIP generation, delivery, and cleanup are NOT implemented here.
 * 
 * This migration creates the pivot table linking downloads to assets.
 * 
 * Rules:
 * - Snapshot downloads: Asset list is immutable after creation
 * - Living downloads: Asset list can change over time (mutable)
 * - is_primary flag marks primary/first asset (for thumbnails/previews)
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
        Schema::create('download_asset', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Relationships
            $table->uuid('download_id');
            $table->uuid('asset_id');

            // Flags
            $table->boolean('is_primary')->default(false); // Primary/first asset for previews

            // Timestamps
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('download_id')
                ->references('id')
                ->on('downloads')
                ->onDelete('cascade');

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('download_id');
            $table->index('asset_id');
            $table->index('is_primary');

            // Unique constraint: Each asset can only appear once per download
            $table->unique(['download_id', 'asset_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_asset');
    }
};
