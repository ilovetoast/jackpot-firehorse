<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase B5: Add override tracking for hybrid metadata fields.
     * Allows explicit override intent to be recorded when users override automatic values.
     */
    public function up(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            // Add overridden_at timestamp (nullable - only set when override occurs)
            if (!Schema::hasColumn('asset_metadata', 'overridden_at')) {
                $table->timestamp('overridden_at')->nullable()->after('approved_at');
            }

            // Add overridden_by user_id (nullable - only set when override occurs)
            if (!Schema::hasColumn('asset_metadata', 'overridden_by')) {
                $table->foreignId('overridden_by')->nullable()->after('overridden_at')
                    ->constrained('users')->onDelete('set null');
            }

            // Add index for querying overridden metadata
            if (!Schema::hasColumn('asset_metadata', 'overridden_at')) {
                $table->index('overridden_at');
            }
        });

        // Update existing records: source = 'user' means manual, source = 'ai' means automatic
        // For hybrid fields, we'll need to check the field's population_mode
        // This migration is additive - existing data remains valid
        // New hybrid fields will use source = 'automatic' initially
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            if (Schema::hasColumn('asset_metadata', 'overridden_by')) {
                $table->dropForeign(['overridden_by']);
                $table->dropColumn('overridden_by');
            }
            if (Schema::hasColumn('asset_metadata', 'overridden_at')) {
                $table->dropIndex(['overridden_at']);
                $table->dropColumn('overridden_at');
            }
        });
    }
};
