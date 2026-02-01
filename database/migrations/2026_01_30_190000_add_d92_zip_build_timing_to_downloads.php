<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D9.2 — ZIP build timing & observability.
 *
 * Architectural lock: ZIP build timing is observability only. It must never affect
 * permissions, access, or UX state directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->timestamp('zip_build_started_at')->nullable()->after('zip_status');
            $table->timestamp('zip_build_completed_at')->nullable()->after('zip_build_started_at');
            $table->timestamp('zip_build_failed_at')->nullable()->after('zip_build_completed_at');

            $table->index('zip_build_started_at');
            $table->index('zip_build_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropIndex(['zip_build_started_at']);
            $table->dropIndex(['zip_build_completed_at']);
            $table->dropColumn([
                'zip_build_started_at',
                'zip_build_completed_at',
                'zip_build_failed_at',
            ]);
        });
    }
};
