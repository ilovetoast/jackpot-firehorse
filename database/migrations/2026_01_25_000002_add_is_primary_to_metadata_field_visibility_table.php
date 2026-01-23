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
     * Adds is_primary column to metadata_field_visibility table for category-scoped primary filter placement.
     * 
     * ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
     * A field may be primary in Photography but secondary in Logos.
     * 
     * This column stores category-specific primary filter settings:
     * - null: No override (fallback to metadata_fields.is_primary for backward compatibility)
     * - true: Field is primary for this category
     * - false: Field is secondary for this category
     */
    public function up(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            // Add is_primary column with safe default null
            // null = no override (fallback to metadata_fields.is_primary)
            // true = primary for this category
            // false = secondary for this category
            if (!Schema::hasColumn('metadata_field_visibility', 'is_primary')) {
                $table->boolean('is_primary')->nullable()->default(null)->after('is_filter_hidden');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_field_visibility', 'is_primary')) {
                $table->dropColumn('is_primary');
            }
        });
    }
};
