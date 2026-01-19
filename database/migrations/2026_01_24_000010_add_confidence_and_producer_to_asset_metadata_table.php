<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase B7: Add confidence and producer metadata to asset_metadata table.
     * These fields track the source and reliability of metadata values.
     */
    public function up(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            // Note: confidence column already exists (from original migration)
            // This migration only adds producer column
            
            // Add producer column (string, nullable)
            // Tracks the source/system that produced this metadata value
            // Examples: 'exif', 'ai', 'user', 'system'
            if (!Schema::hasColumn('asset_metadata', 'producer')) {
                $table->string('producer', 50)->nullable()->after('confidence');
            }

            // Add index for querying by producer
            if (!Schema::hasColumn('asset_metadata', 'producer')) {
                $table->index('producer');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            if (Schema::hasColumn('asset_metadata', 'producer')) {
                $table->dropIndex(['producer']);
                $table->dropColumn('producer');
            }
            // Note: confidence column is not dropped here - it exists from original migration
        });
    }
};
