<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce dominant_color_bucket as filter-only field.
 * - show_on_edit = false (never in Quick View)
 * - is_filterable = true (can appear in secondary filters when enabled)
 * - show_in_filters = true
 *
 * Config filter_only_enforced_fields + metadata_field_visibility control placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->update([
                'show_on_edit' => false,
                'show_in_filters' => true,
                'is_filterable' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->update([
                'show_on_edit' => true,
                'show_in_filters' => false,
                'is_filterable' => false,
                'updated_at' => now(),
            ]);
    }
};
