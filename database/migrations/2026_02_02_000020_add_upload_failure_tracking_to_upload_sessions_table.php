<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase U-1: Add upload failure tracking to upload_sessions table.
 *
 * Supports:
 * - failure_reason (enum string: presign_failed, multipart_init_failed, etc.)
 * - failure_count (increment on each failure)
 * - last_failed_at (timestamp)
 * - escalation_ticket_id (linked support ticket)
 * - upload_options (JSON for exception trace and observability)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->unsignedInteger('failure_count')->default(0)->after('failure_reason');
            $table->timestamp('last_failed_at')->nullable()->after('failure_count');
            $table->unsignedBigInteger('escalation_ticket_id')->nullable()->after('last_failed_at');
            $table->json('upload_options')->nullable()->after('escalation_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn(['failure_count', 'last_failed_at', 'escalation_ticket_id', 'upload_options']);
        });
    }
};
