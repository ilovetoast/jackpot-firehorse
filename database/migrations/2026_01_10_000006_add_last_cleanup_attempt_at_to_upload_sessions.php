<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds optional last_cleanup_attempt_at field to track cleanup attempts.
     * This is an additive field for observability and can be used to:
     * - Track when cleanup was last attempted for a session
     * - Prevent duplicate cleanup attempts within a short window
     * - Audit cleanup operations
     *
     * This field is optional and can be used by cleanup services if needed.
     */
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Optional: Track when cleanup was last attempted for this session
            // This helps with observability and can prevent duplicate cleanup attempts
            $table->timestamp('last_cleanup_attempt_at')->nullable()->after('last_activity_at');
            
            // Index for efficient queries of sessions needing cleanup
            $table->index(['status', 'last_cleanup_attempt_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_cleanup_attempt_at']);
            $table->dropColumn('last_cleanup_attempt_at');
        });
    }
};
