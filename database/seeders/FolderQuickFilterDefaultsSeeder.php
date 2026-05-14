<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Filters\FolderQuickFilterDefaultsApplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Folder Quick Filter defaults seeder.
 *
 * Phase 4.1 update: the per-tenant / per-category logic now lives in
 * {@see FolderQuickFilterDefaultsApplier} so:
 *   - `SystemCategoryService::addTemplateToBrand` can apply defaults for a
 *     single new category at tenant-bootstrap time without re-running the
 *     full seeder.
 *   - There is exactly one place that decides "should this row become a
 *     seeded quick filter?" — no duplicate seed logic.
 *
 * This seeder remains the canonical CLI entry point and the only thing
 * registered in DatabaseSeeder.
 */
class FolderQuickFilterDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasColumn('metadata_field_visibility', 'show_in_folder_quick_filters')) {
            $this->command?->warn(
                '[FolderQuickFilterDefaultsSeeder] metadata_field_visibility.show_in_folder_quick_filters '
                .'is missing — run migration 2026_05_14_140000_add_folder_quick_filter_columns_* first. Skipping.'
            );

            return;
        }

        /** @var FolderQuickFilterDefaultsApplier $applier */
        $applier = app(FolderQuickFilterDefaultsApplier::class);

        $totals = [
            'created' => 0,
            'updated_quick_filter_only' => 0,
            'skipped_existing_source' => 0,
            'skipped_ineligible' => 0,
            'skipped_suppressed' => 0,
            'skipped_unknown_field' => 0,
            'skipped_unknown_folder' => 0,
        ];

        foreach (Tenant::query()->cursor() as $tenant) {
            $stats = $applier->applyForTenant($tenant);
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) ($stats[$k] ?? 0);
            }
        }

        Log::info('[FolderQuickFilterDefaultsSeeder] Phase 2 default seeding complete.', $totals);
        $this->command?->info(
            '[FolderQuickFilterDefaultsSeeder] '
            .json_encode($totals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
