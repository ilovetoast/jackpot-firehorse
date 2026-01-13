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
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Multipart upload state tracking (JSON)
            // Structure: {
            //   "initiated_at": "2026-01-10T12:00:00Z",
            //   "completed_parts": { "1": "etag1", "2": "etag2", ... },
            //   "status": "initiated|uploading|completed|aborted"
            // }
            $table->json('multipart_state')->nullable()->after('multipart_upload_id');
            
            // Part size for multipart uploads (10MB default)
            $table->unsignedInteger('part_size')->nullable()->after('multipart_state');
            
            // Total number of parts for multipart uploads
            $table->unsignedInteger('total_parts')->nullable()->after('part_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn(['multipart_state', 'part_size', 'total_parts']);
        });
    }
};
