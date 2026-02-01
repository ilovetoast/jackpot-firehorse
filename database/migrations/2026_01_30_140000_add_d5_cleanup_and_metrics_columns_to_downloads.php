<?php

/**
 * Phase D5 — Download Metrics & Expiration Cleanup
 *
 * Adds nullable timestamps for cleanup verification and metrics:
 * - zip_deleted_at: when artifact was deleted from storage
 * - cleanup_verified_at: when we confirmed file absence
 * - cleanup_failed_at: when verification failed (file still present)
 *
 * No breaking changes. Existing rows remain valid.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->timestamp('zip_deleted_at')->nullable()->after('zip_path');
            $table->timestamp('cleanup_verified_at')->nullable()->after('zip_deleted_at');
            $table->timestamp('cleanup_failed_at')->nullable()->after('cleanup_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['zip_deleted_at', 'cleanup_verified_at', 'cleanup_failed_at']);
        });
    }
};
