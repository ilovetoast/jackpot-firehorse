<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Jobs\ProvisionSystemCategoryToExistingBrandsJob;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SystemCategoryService
 *
 * Handles business logic for managing system category templates.
 * System categories are global templates that are copied to brands when created.
 */
class SystemCategoryService
{
    /**
     * Get all system category templates (latest versions only).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SystemCategory>
     */
    public function getAllTemplates()
    {
        $latestIds = $this->latestTemplateIdsForQuery(null);

        if ($latestIds === []) {
            return SystemCategory::query()->whereRaw('0 = 1')->get();
        }

        return SystemCategory::whereIn('id', $latestIds)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get templates by asset type (latest versions only).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SystemCategory>
     */
    public function getTemplatesByAssetType(AssetType $assetType)
    {
        $latestIds = $this->latestTemplateIdsForQuery($assetType);

        if ($latestIds === []) {
            return SystemCategory::query()->whereRaw('0 = 1')->get();
        }

        return SystemCategory::whereIn('id', $latestIds)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Latest system_categories.id per (slug, asset_type): highest version, then highest id (tie-break).
     * Two queries total (subquery + whereIn) — avoids N+1 from per-pair ->first() loops (e.g. brand edit).
     * Uses ROW_NUMBER() (MySQL 8+, MariaDB 10.2+, SQLite 3.25+).
     *
     * @return list<int>
     */
    private function latestTemplateIdsForQuery(?AssetType $assetType): array
    {
        $base = DB::table('system_categories');
        if ($assetType !== null) {
            $base->where('asset_type', $assetType->value);
        }

        $ranked = $base->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY slug, asset_type ORDER BY version DESC, id DESC) as rn');

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * Create a new system category template.
     */
    public function createTemplate(array $data): SystemCategory
    {
        $seedBundlePreset = $data['seed_bundle_preset'] ?? 'none';
        $seedFieldTypes = $data['seed_field_types'] ?? null;
        unset($data['seed_bundle_preset'], $data['seed_field_types']);

        // Generate slug if not provided
        if (! isset($data['slug']) || empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Ensure slug is unique within asset_type (check if any version exists with this slug)
        $baseSlug = $data['slug'];
        $slug = $baseSlug;
        $counter = 1;
        while (SystemCategory::where('asset_type', $data['asset_type'])
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }
        $data['slug'] = $slug;

        // Set defaults
        $data['is_hidden'] = $data['is_hidden'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['version'] = $data['version'] ?? 1; // New templates start at version 1
        // Admin-created templates are catalog-only unless explicitly auto_provisioned
        if (! array_key_exists('auto_provision', $data)) {
            $data['auto_provision'] = false;
        }

        $template = SystemCategory::create($data);

        if ($template->auto_provision) {
            ProvisionSystemCategoryToExistingBrandsJob::dispatch($template->id);
        }

        if (in_array($seedBundlePreset, ['minimal', 'by_field_types', 'photography_like'], true)) {
            if ($seedBundlePreset !== 'by_field_types' || (is_array($seedFieldTypes) && $seedFieldTypes !== [])) {
                try {
                    app(SystemCategoryFieldBundlePresetService::class)->seed(
                        $template,
                        $seedBundlePreset,
                        is_array($seedFieldTypes) ? $seedFieldTypes : null
                    );
                } catch (\Throwable $e) {
                    \Log::warning('[SystemCategoryService] Bundle preset seed failed', [
                        'template_id' => $template->id,
                        'preset' => $seedBundlePreset,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $template;
    }

    /**
     * Update a system category template in place (no versioning).
     * After save, display name/icon are pushed to all brand category rows for this template (slug + type).
     */
    public function updateTemplate(SystemCategory $systemCategory, array $data): SystemCategory
    {
        $wasAutoProvision = (bool) $systemCategory->auto_provision;

        // Generate slug if name changed and slug not provided
        if (isset($data['name']) && $data['name'] !== $systemCategory->name) {
            if (! isset($data['slug']) || $data['slug'] === $systemCategory->slug) {
                $data['slug'] = Str::slug($data['name']);
            }
        }

        // Ensure slug is unique within asset_type (excluding current record)
        if (isset($data['slug']) && $data['slug'] !== $systemCategory->slug) {
            $baseSlug = $data['slug'];
            $slug = $baseSlug;
            $counter = 1;
            while (SystemCategory::where('asset_type', $data['asset_type'] ?? $systemCategory->asset_type)
                ->where('slug', $slug)
                ->where('id', '!=', $systemCategory->id)
                ->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $systemCategory->update($data);

        $fresh = $systemCategory->fresh();

        if (! $wasAutoProvision && $fresh && $fresh->auto_provision) {
            ProvisionSystemCategoryToExistingBrandsJob::dispatch($fresh->id);
        }

        if ($fresh) {
            $this->pushTemplateDisplayToBrandCategories($fresh);
        }

        return $fresh;
    }

    /**
     * Push template display fields (name, icon, system link) to every brand category row for this template.
     * Tenants cannot edit system name/icon locally; the catalog template is the source of truth.
     */
    public function pushTemplateDisplayToBrandCategories(SystemCategory $template): int
    {
        $icon = $template->icon ?? 'folder';

        return Category::query()
            ->whereNull('deleted_at')
            ->where('is_system', true)
            ->where('asset_type', $template->asset_type)
            ->where(function ($q) use ($template) {
                $q->where('system_category_id', $template->id)
                    ->orWhere('slug', $template->slug);
            })
            ->update([
                'name' => $template->name,
                'icon' => $icon,
                'system_category_id' => $template->id,
                'system_version' => $template->version,
                'upgrade_available' => false,
            ]);
    }

    /**
     * Delete a system category template.
     * Also soft-deletes all brand categories that were created from this template.
     */
    public function deleteTemplate(SystemCategory $systemCategory): bool
    {
        // Get all system category IDs for this template (all versions with same slug/asset_type)
        $templateIds = SystemCategory::where('slug', $systemCategory->slug)
            ->where('asset_type', $systemCategory->asset_type)
            ->pluck('id')
            ->toArray();

        // Find all brand categories that were created from this template:
        // 1. Categories linked via system_category_id (any version)
        // 2. Categories with matching slug and asset_type (legacy categories)
        $brandCategories = Category::where(function ($query) use ($templateIds, $systemCategory) {
            $query->whereIn('system_category_id', $templateIds)
                ->orWhere(function ($q) use ($systemCategory) {
                    $q->where('slug', $systemCategory->slug)
                        ->where('asset_type', $systemCategory->asset_type->value)
                        ->where('is_system', true);
                });
        })
            ->get();

        // Mark all brand categories for deletion (user must accept the deletion)
        foreach ($brandCategories as $category) {
            $category->update(['deletion_available' => true]);
        }

        // Delete the system category template itself
        return $systemCategory->delete();
    }

    /**
     * Get or create the reference_material category for a brand.
     * Used by Brand Builder for reference materials (PDFs, screenshots, ads, packaging).
     */
    public function getOrCreateReferenceMaterialCategory(Brand $brand): Category
    {
        $template = SystemCategory::where('slug', 'reference_material')
            ->where('asset_type', AssetType::REFERENCE)
            ->orderByDesc('version')
            ->first();

        if (! $template) {
            throw new \RuntimeException('reference_material system category template not found. Run SystemCategoryTemplateSeeder.');
        }

        $existing = Category::where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::REFERENCE)
            ->where('slug', 'reference_material')
            ->first();

        if ($existing) {
            return $existing;
        }

        $this->addTemplateToBrand($brand, $template);

        return Category::where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::REFERENCE)
            ->where('slug', 'reference_material')
            ->firstOrFail();
    }

    /**
     * Sync system category templates to a brand.
     * Creates brand-specific categories from the templates (latest versions only).
     */
    public function syncToBrand(Brand $brand): void
    {
        $templates = $this->getAllTemplates();

        foreach ($templates as $template) {
            if (! $template->auto_provision) {
                continue;
            }
            $this->addTemplateToBrand($brand, $template);
        }
    }

    /**
     * Add a system template row to every brand that does not yet have this slug (auto-provision backfill).
     * New rows use is_hidden=true so existing tenants see the folder under Hidden until they show it.
     *
     * @return int Number of brand category rows created (0 if slug already existed for that brand)
     */
    public function provisionAutoProvisionTemplateToExistingBrands(SystemCategory $template): int
    {
        $created = 0;

        Brand::query()->orderBy('id')->chunkById(200, function ($brands) use ($template, &$created) {
            foreach ($brands as $brand) {
                $row = $this->addTemplateToBrand($brand, $template, forceHiddenOnCreate: true);
                if ($row !== null) {
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Add a single system category template to a brand.
     * Creates a brand-specific category from the template if it doesn't already exist.
     *
     * @param  bool  $forceHiddenOnCreate  When true, new rows are hidden on the brand (backfill to existing brands).
     * @return Category|null The created or restored category, or null if it already exists and is not trashed
     */
    public function addTemplateToBrand(Brand $brand, SystemCategory $template, bool $forceHiddenOnCreate = false): ?Category
    {
        // Check if category already exists for this brand (including soft-deleted)
        $existing = Category::withTrashed()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', $template->asset_type)
            ->where('slug', $template->slug)
            ->first();

        if ($existing) {
            // If it's soft-deleted, restore it and update it with the latest template data
            if ($existing->trashed()) {
                $existing->restore();
                // Update with latest template data
                $existing->update([
                    'name' => $template->name,
                    'icon' => $template->icon,
                    'is_hidden' => $forceHiddenOnCreate ? true : $template->is_hidden,
                    'system_category_id' => $template->id,
                    'system_version' => $template->version,
                    'upgrade_available' => false,
                ]);
                $fresh = $existing->fresh();
                $settings = $fresh->settings ?? [];
                if (! array_key_exists('ebi_enabled', $settings)) {
                    $settings['ebi_enabled'] = Category::defaultEbiEnabledForSystemSlug($template->slug);
                    $fresh->update(['settings' => $settings]);
                    $fresh->refresh();
                }

                return $fresh;
            }

            return null;
        }

        // Create category from template (latest version)
        // Note: is_locked is set to true for system categories and is site admin only
        // Tenants cannot change is_locked - it can only be modified by site administrators
        $category = Category::create([
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'asset_type' => $template->asset_type,
            'name' => $template->name,
            'slug' => $template->slug,
            'icon' => $template->icon,
            'is_system' => true,
            'is_locked' => true, // System categories are locked by default (site admin only)
            'is_private' => false, // System categories are never private
            'is_hidden' => $forceHiddenOnCreate ? true : $template->is_hidden,
            'system_category_id' => $template->id,
            'system_version' => $template->version,
            'upgrade_available' => false,
            'settings' => [
                'ebi_enabled' => Category::defaultEbiEnabledForSystemSlug($template->slug),
            ],
        ]);

        // Phase 3b: Apply seeded default metadata visibility for new category (from config/metadata_category_defaults.php)
        $tenant = $brand->tenant ?? \App\Models\Tenant::find($brand->tenant_id);
        if ($tenant) {
            try {
                app(TenantMetadataVisibilityService::class)->applySeededDefaultsForCategory($tenant, $category);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SystemCategoryService: failed to apply seeded defaults for new category', [
                    'category_id' => $category->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 4.1 — apply Folder Quick Filter defaults for this brand-new
            // system category. Without this, fresh tenants only got quick
            // filters when an operator manually re-ran the FolderQuickFilterDefaultsSeeder.
            // The applier is itself idempotent + defensive: it skips
            // suppressed rows and never overwrites an admin-touched source.
            try {
                app(\App\Services\Filters\FolderQuickFilterDefaultsApplier::class)
                    ->applyForCategory($category);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SystemCategoryService: failed to apply quick filter defaults for new category', [
                    'category_id' => $category->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $category;
    }
}
