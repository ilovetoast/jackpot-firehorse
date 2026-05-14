<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Folder Quick Filters / Contextual Filtering Navigation.
 *
 * Adds per-folder quick filter assignment columns onto the existing
 * {@see metadata_field_visibility} pivot. We deliberately reuse this table
 * rather than creating a parallel one because it already stores per-tenant,
 * per-brand, per-category visibility for each metadata filter — exactly the
 * scope a quick filter assignment needs.
 *
 * Columns:
 *   - show_in_folder_quick_filters: master per-(tenant, brand, category, filter)
 *     opt-in flag. Defaults to false so existing rows behave unchanged. The
 *     Phase 2 seeder flips this to true for a curated default set.
 *
 *   - folder_quick_filter_order: explicit manual ordering inside the quick
 *     filter strip for one folder. NULL means "fall back to natural order"
 *     (alphabetical or seeded order — see the assignment service).
 *
 *   - folder_quick_filter_weight: future-only AI / recommendation score. Higher
 *     weight = more important. NULL means "unscored". Phase 2 does not read it.
 *
 *   - folder_quick_filter_source: provenance for who turned this on. Values
 *     today: 'seeded' (the Phase 2 default seeder), 'manual' (admin toggle),
 *     'ai_suggested' (reserved for Phase 3+). Stored as nullable string so
 *     future values do not require schema changes.
 *
 * The migration is intentionally additive only:
 *   - no rename of existing columns
 *   - no rebuild of the table
 *   - no data backfill
 *   - default = false, so behavior of every existing row is unchanged
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metadata_field_visibility')) {
            return;
        }

        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (! Schema::hasColumn('metadata_field_visibility', 'show_in_folder_quick_filters')) {
                $table->boolean('show_in_folder_quick_filters')
                    ->default(false)
                    ->after('is_required');
            }
            if (! Schema::hasColumn('metadata_field_visibility', 'folder_quick_filter_order')) {
                $table->integer('folder_quick_filter_order')
                    ->nullable()
                    ->after('show_in_folder_quick_filters');
            }
            if (! Schema::hasColumn('metadata_field_visibility', 'folder_quick_filter_weight')) {
                $table->integer('folder_quick_filter_weight')
                    ->nullable()
                    ->after('folder_quick_filter_order');
            }
            if (! Schema::hasColumn('metadata_field_visibility', 'folder_quick_filter_source')) {
                $table->string('folder_quick_filter_source', 32)
                    ->nullable()
                    ->after('folder_quick_filter_weight');
            }
        });

        // Composite index that supports the common Phase 2 query: "give me the
        // quick filters for this category, ordered, where show_in_folder_quick_filters = true".
        // We index (tenant_id, category_id, show_in_folder_quick_filters) so the
        // assignment service can do a tight category-scoped lookup without a
        // table scan as quick filter usage grows.
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            $indexName = 'mfv_quick_filter_lookup_idx';
            if (! $this->indexExists('metadata_field_visibility', $indexName)) {
                $table->index(
                    ['tenant_id', 'category_id', 'show_in_folder_quick_filters'],
                    $indexName
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('metadata_field_visibility')) {
            return;
        }

        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            $indexName = 'mfv_quick_filter_lookup_idx';
            if ($this->indexExists('metadata_field_visibility', $indexName)) {
                $table->dropIndex($indexName);
            }

            foreach ([
                'folder_quick_filter_source',
                'folder_quick_filter_weight',
                'folder_quick_filter_order',
                'show_in_folder_quick_filters',
            ] as $column) {
                if (Schema::hasColumn('metadata_field_visibility', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        // Mirrors the project pattern used in
        // 2026_04_21_200000_drop_global_key_unique_from_metadata_fields_table.
        $databaseName = Schema::getConnection()->getDatabaseName();

        $result = DB::select(
            'SELECT COUNT(*) AS count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$databaseName, $table, $indexName]
        );

        return (int) ($result[0]->count ?? 0) > 0;
    }
};
