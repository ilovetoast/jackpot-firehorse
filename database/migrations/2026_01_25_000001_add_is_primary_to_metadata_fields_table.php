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
 * NOTE: This column is DEPRECATED in favor of category-scoped is_primary in metadata_field_visibility.
 * 
 * ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
 * A field may be primary in Photography but secondary in Logos.
 * 
 * This global is_primary column is kept for backward compatibility only.
 * New implementations should use metadata_field_visibility.is_primary (category-scoped).
 * 
 * TODO: Migrate all fields to category overrides and remove this column.
 */
public function up(): void
{
    Schema::table('metadata_fields', function (Blueprint $table) {
        // Add is_primary column with safe default false
        // DEPRECATED: Use metadata_field_visibility.is_primary (category-scoped) instead
        if (!Schema::hasColumn('metadata_fields', 'is_primary')) {
            $table->boolean('is_primary')->default(false)->after('show_in_filters');
        }
    });

        // Update existing rows to have safe default (idempotent)
        if (Schema::hasColumn('metadata_fields', 'is_primary')) {
            DB::table('metadata_fields')
                ->whereNull('is_primary')
                ->update(['is_primary' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'is_primary')) {
                $table->dropColumn('is_primary');
            }
        });
    }
};
