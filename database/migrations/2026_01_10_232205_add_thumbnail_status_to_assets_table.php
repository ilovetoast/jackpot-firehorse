<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds thumbnail state tracking fields to assets table.
     * These fields track the status of thumbnail generation independently
     * from the main asset processing pipeline.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Thumbnail generation status: pending, processing, completed, failed
            // NULL means thumbnails haven't been attempted yet
            $table->string('thumbnail_status')->nullable()->after('status');
            
            // Error message if thumbnail generation failed
            $table->text('thumbnail_error')->nullable()->after('thumbnail_status');
            
            // Index for querying assets by thumbnail status
            $table->index('thumbnail_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['thumbnail_status']);
            $table->dropColumn(['thumbnail_status', 'thumbnail_error']);
        });
    }
};
