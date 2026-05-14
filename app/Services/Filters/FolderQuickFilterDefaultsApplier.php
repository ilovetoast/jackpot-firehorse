<?php

namespace App\Services\Filters;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.1 — runtime per-tenant / per-category quick filter defaults applier.
 *
 * Originally the same logic only existed inside FolderQuickFilterDefaultsSeeder
 * and ran via the global `db:seed` pipeline. New tenants created from app code
 * (signup, gateway register, add-company, agency-incubated client) call
 * `Brand::created` → `SystemCategorySeeder::seedForBrand` → `SystemCategoryService::addTemplateToBrand`,
 * which builds new system categories at runtime — and those categories never
 * received their seeded quick filter defaults until the operator re-ran the
 * seeder manually.
 *
 * This service factors out the per-(tenant, category) and per-(category, field)
 * application paths so:
 *   - The seeder can keep its global iteration but delegate the per-category
 *     decision to ONE place (no duplicate rules).
 *   - SystemCategoryService can call `applyForCategory(...)` on each new
 *     category, so quick filters appear immediately for new tenants without
 *     a manual seed pass.
 *
 * Defensive contract (matches the seeder):
 *   1. Never overwrite a row whose `folder_quick_filter_source` is already set.
 *   2. Never enable a filter that is suppressed (`is_hidden = true`) for the
 *      folder.
 *   3. Never bypass FolderQuickFilterEligibilityService.
 *   4. Idempotent — safe to invoke multiple times.
 *   5. No-op when the Phase 2 schema migration has not yet run.
 */
class FolderQuickFilterDefaultsApplier
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        protected FolderQuickFilterEligibilityService $eligibility,
    ) {}

    /**
     * Apply seeded quick filter defaults to every category in a tenant.
     *
     * @return array<string, int> stats keyed by category-result kind.
     */
    public function applyForTenant(Tenant $tenant): array
    {
        if (! $this->schemaReady()) {
            return $this->emptyStats();
        }
        $defaultsBySlug = $this->resolveDefaultsBySlug();
        if ($defaultsBySlug === []) {
            return $this->emptyStats();
        }

        $fieldsByKey = $this->loadReferencedFields($defaultsBySlug);
        $stats = $this->emptyStats();

        Category::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('slug', array_keys($defaultsBySlug))
            ->cursor()
            ->each(function (Category $category) use ($defaultsBySlug, $fieldsByKey, &$stats) {
                $this->applyForCategoryInternal(
                    $category,
                    $defaultsBySlug[(string) $category->slug] ?? [],
                    $fieldsByKey,
                    $stats
                );
            });

        return $stats;
    }

    /**
     * Apply defaults for a single category (called by SystemCategoryService at
     * tenant bootstrap time and by the seeder).
     *
     * @return array<string, int>
     */
    public function applyForCategory(Category $category): array
    {
        $stats = $this->emptyStats();
        if (! $this->schemaReady() || ! $category->exists) {
            return $stats;
        }
        $defaultsBySlug = $this->resolveDefaultsBySlug();
        $keysForSlug = $defaultsBySlug[(string) $category->slug] ?? [];
        if ($keysForSlug === []) {
            return $stats;
        }
        $fieldsByKey = $this->loadReferencedFields([$category->slug => $keysForSlug]);
        $this->applyForCategoryInternal($category, $keysForSlug, $fieldsByKey, $stats);

        return $stats;
    }

    /**
     * @param  list<string>  $keysForSlug  Ordered field keys for the slug.
     * @param  array<string, MetadataField>  $fieldsByKey
     * @param  array<string, int>  $stats  by-reference accumulator.
     */
    private function applyForCategoryInternal(
        Category $category,
        array $keysForSlug,
        array $fieldsByKey,
        array &$stats,
    ): void {
        foreach ($keysForSlug as $index => $fieldKey) {
            $field = $fieldsByKey[$fieldKey] ?? null;
            if (! $field instanceof MetadataField) {
                $stats['skipped_unknown_field']++;

                continue;
            }
            if (! $this->eligibility->isEligible($field)) {
                $stats['skipped_ineligible']++;

                continue;
            }

            $existing = DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $field->id)
                ->where('tenant_id', $category->tenant_id)
                ->where('category_id', $category->id)
                ->where(function ($q) use ($category) {
                    if ($category->brand_id) {
                        $q->where('brand_id', $category->brand_id)->orWhereNull('brand_id');
                    } else {
                        $q->whereNull('brand_id');
                    }
                })
                ->orderByDesc('brand_id')
                ->first();

            if ($existing !== null && (bool) ($existing->is_hidden ?? false)) {
                $stats['skipped_suppressed']++;

                continue;
            }

            if (
                $existing !== null
                && $existing->folder_quick_filter_source !== null
                && $existing->folder_quick_filter_source !== ''
            ) {
                $stats['skipped_existing_source']++;

                continue;
            }

            try {
                $this->assignment->enableQuickFilter($category, $field, [
                    'order' => $index,
                    'source' => FolderQuickFilterAssignmentService::SOURCE_SEEDED,
                ]);
                $existing === null
                    ? $stats['created']++
                    : $stats['updated_quick_filter_only']++;
            } catch (\InvalidArgumentException $e) {
                // Defensive — eligibility race or transient bad state.
                $stats['skipped_ineligible']++;
            }
        }
    }

    private function schemaReady(): bool
    {
        return Schema::hasColumn('metadata_field_visibility', 'show_in_folder_quick_filters');
    }

    /**
     * @return array<string, list<string>>
     */
    public function resolveDefaultsBySlug(): array
    {
        $defaults = (array) config('categories.folder_quick_filter_defaults', []);
        $out = [];
        foreach (['asset_folders', 'execution_folders', 'special_folders'] as $bucket) {
            $entries = $defaults[$bucket] ?? [];
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $slug => $keys) {
                if (! is_string($slug) || ! is_array($keys)) {
                    continue;
                }
                $out[$slug] = array_values(array_filter($keys, 'is_string'));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $defaultsBySlug
     * @return array<string, MetadataField>
     */
    private function loadReferencedFields(array $defaultsBySlug): array
    {
        $referenced = [];
        foreach ($defaultsBySlug as $keys) {
            foreach ($keys as $key) {
                $referenced[$key] = true;
            }
        }
        if ($referenced === []) {
            return [];
        }

        return MetadataField::query()
            ->whereIn('key', array_keys($referenced))
            ->whereNull('tenant_id')
            ->get()
            ->keyBy('key')
            ->all();
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'created' => 0,
            'updated_quick_filter_only' => 0,
            'skipped_existing_source' => 0,
            'skipped_ineligible' => 0,
            'skipped_suppressed' => 0,
            'skipped_unknown_field' => 0,
            'skipped_unknown_folder' => 0,
        ];
    }
}
