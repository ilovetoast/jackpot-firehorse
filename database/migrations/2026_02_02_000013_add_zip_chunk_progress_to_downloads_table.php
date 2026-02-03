<?php

/**
 * Phase D-Progress — ZIP Chunk Progress & Heartbeat (observability only).
 *
 * Adds nullable fields for progress visibility:
 * - zip_total_chunks: total chunk count when build started
 * - zip_last_progress_at: timestamp of last chunk completion (heartbeat)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->unsignedInteger('zip_total_chunks')->nullable()->after('zip_build_chunk_index');
            $table->timestamp('zip_last_progress_at')->nullable()->after('zip_total_chunks');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['zip_total_chunks', 'zip_last_progress_at']);
        });
    }
};
