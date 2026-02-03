<?php

/**
 * Add ZIP build failure tracking to downloads table.
 *
 * Supports:
 * - failure_reason enum (timeout, disk_full, s3_read_error, permission_error, unknown)
 * - failure_count (increment on each failure)
 * - last_failed_at (timestamp)
 * - zip_build_chunk_index (for resumable chunked ZIP creation)
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('failure_reason', 50)->nullable()->after('zip_build_failed_at');
            $table->unsignedInteger('failure_count')->default(0)->after('failure_reason');
            $table->timestamp('last_failed_at')->nullable()->after('failure_count');
            $table->unsignedInteger('zip_build_chunk_index')->default(0)->after('last_failed_at');
            $table->unsignedBigInteger('escalation_ticket_id')->nullable()->after('zip_build_chunk_index');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'failure_count', 'last_failed_at', 'zip_build_chunk_index', 'escalation_ticket_id']);
        });
    }
};
