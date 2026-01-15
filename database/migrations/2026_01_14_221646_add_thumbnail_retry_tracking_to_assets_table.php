<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds fields to track manual thumbnail retry attempts.
     * This is part of the thumbnail retry feature that allows users to manually
     * retry thumbnail generation from the asset drawer UI.
     * 
     * IMPORTANT: This feature respects the locked thumbnail pipeline:
     * - Does not modify existing GenerateThumbnailsJob
     * - Does not mutate Asset.status
     * - Retry attempts are tracked for audit purposes
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('thumbnail_retry_count')->default(0)->after('thumbnail_started_at');
            $table->timestamp('thumbnail_last_retry_at')->nullable()->after('thumbnail_retry_count');
            
            // Index for querying assets with retry attempts
            $table->index('thumbnail_retry_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['thumbnail_retry_count']);
            $table->dropColumn(['thumbnail_retry_count', 'thumbnail_last_retry_at']);
        });
    }
};
