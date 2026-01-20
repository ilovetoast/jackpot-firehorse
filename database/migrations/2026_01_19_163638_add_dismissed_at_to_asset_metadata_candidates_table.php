<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase B9: Add dismissed_at column to asset_metadata_candidates table.
     * Allows candidates to be marked as dismissed (rejected) during human review
     * without deleting them, preserving audit history.
     */
    public function up(): void
    {
        Schema::table('asset_metadata_candidates', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_metadata_candidates', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('resolved_at');
                // Add index for querying non-dismissed candidates
                $table->index('dismissed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_metadata_candidates', function (Blueprint $table) {
            if (Schema::hasColumn('asset_metadata_candidates', 'dismissed_at')) {
                $table->dropIndex(['dismissed_at']);
                $table->dropColumn('dismissed_at');
            }
        });
    }
};
