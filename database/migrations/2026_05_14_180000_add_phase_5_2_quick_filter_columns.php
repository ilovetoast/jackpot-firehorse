<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.2 — additive columns for pinning + metadata-quality signals.
 *
 * `metadata_field_visibility`:
 *   - `is_pinned_folder_quick_filter` boolean. Pinned quick filters sort
 *     before non-pinned and resist overflow hiding. Per-folder, per-tenant
 *     (mirrors the rest of the row's scope).
 *
 * `metadata_fields`:
 *   - `estimated_distinct_value_count` (nullable int) — last computed
 *     cardinality estimate (option count or DISTINCT(value_json)). Hydrated
 *     opportunistically by Phase 5 facet calls.
 *   - `last_facet_usage_at` (nullable timestamp) — most recent flyout open
 *     across all folders. Used by quality heuristics.
 *   - `facet_usage_count` (default 0) — running counter of flyout opens.
 *   - `is_high_cardinality` (default false) — admin-reviewable flag set when
 *     `estimated_distinct_value_count > max_distinct_values_for_quick_filter`.
 *     Phase 5.2 surfaces a warning; Phase 6+ can auto-suppress.
 *   - `is_low_quality_candidate` (default false) — heuristic flag for
 *     filters that are technically eligible but probably shouldn't be in
 *     the sidebar (OCR junk, AI-tag explosions, etc.). Reserved for
 *     future quality scoring; defaults conservative.
 *
 * All columns are additive + idempotent. Index on
 * `(tenant_id, category_id, is_pinned_folder_quick_filter)` so the assignment
 * service can quickly resolve "pinned filters in this folder" when sorting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metadata_field_visibility')) {
            return;
        }
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (! Schema::hasColumn('metadata_field_visibility', 'is_pinned_folder_quick_filter')) {
                $table->boolean('is_pinned_folder_quick_filter')
                    ->default(false)
                    ->after('folder_quick_filter_source');
            }
        });
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            $indexName = 'mfv_quick_filter_pinned_idx';
            if (! $this->indexExists('metadata_field_visibility', $indexName)) {
                $table->index(
                    ['tenant_id', 'category_id', 'is_pinned_folder_quick_filter'],
                    $indexName
                );
            }
        });

        if (! Schema::hasTable('metadata_fields')) {
            return;
        }
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (! Schema::hasColumn('metadata_fields', 'estimated_distinct_value_count')) {
                $table->unsignedInteger('estimated_distinct_value_count')
                    ->nullable()
                    ->after('display_widget');
            }
            if (! Schema::hasColumn('metadata_fields', 'last_facet_usage_at')) {
                $table->timestamp('last_facet_usage_at')
                    ->nullable()
                    ->after('estimated_distinct_value_count');
            }
            if (! Schema::hasColumn('metadata_fields', 'facet_usage_count')) {
                $table->unsignedInteger('facet_usage_count')
                    ->default(0)
                    ->after('last_facet_usage_at');
            }
            if (! Schema::hasColumn('metadata_fields', 'is_high_cardinality')) {
                $table->boolean('is_high_cardinality')
                    ->default(false)
                    ->after('facet_usage_count');
            }
            if (! Schema::hasColumn('metadata_fields', 'is_low_quality_candidate')) {
                $table->boolean('is_low_quality_candidate')
                    ->default(false)
                    ->after('is_high_cardinality');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('metadata_field_visibility')) {
            Schema::table('metadata_field_visibility', function (Blueprint $table) {
                if ($this->indexExists('metadata_field_visibility', 'mfv_quick_filter_pinned_idx')) {
                    $table->dropIndex('mfv_quick_filter_pinned_idx');
                }
                if (Schema::hasColumn('metadata_field_visibility', 'is_pinned_folder_quick_filter')) {
                    $table->dropColumn('is_pinned_folder_quick_filter');
                }
            });
        }
        if (Schema::hasTable('metadata_fields')) {
            Schema::table('metadata_fields', function (Blueprint $table) {
                foreach ([
                    'estimated_distinct_value_count',
                    'last_facet_usage_at',
                    'facet_usage_count',
                    'is_high_cardinality',
                    'is_low_quality_candidate',
                ] as $col) {
                    if (Schema::hasColumn('metadata_fields', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return count($rows) > 0;
    }
};
