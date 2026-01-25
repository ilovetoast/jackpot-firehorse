<?php

namespace App\Services;

use App\Enums\AssetType;
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
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllTemplates()
    {
        // Get latest version of each template (highest version per slug/asset_type)
        // Get all unique slug/asset_type combinations
        $uniqueTemplates = SystemCategory::select('slug', 'asset_type')
            ->distinct()
            ->get();

        // For each combination, get the record with the highest version
        $latestIds = [];
        foreach ($uniqueTemplates as $template) {
            $latest = SystemCategory::where('slug', $template->slug)
                ->where('asset_type', $template->asset_type)
                ->orderBy('version', 'desc')
                ->orderBy('id', 'desc') // If same version, get the latest created
                ->first();
            
            if ($latest) {
                $latestIds[] = $latest->id;
            }
        }

        return SystemCategory::whereIn('id', $latestIds)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get templates by asset type (latest versions only).
     *
     * @param AssetType $assetType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTemplatesByAssetType(AssetType $assetType)
    {
        // Get latest version of each template for this asset type
        // First, find max version for each slug/asset_type combination for this asset type
        $maxVersions = SystemCategory::where('asset_type', $assetType)
            ->select('slug', 'asset_type', DB::raw('MAX(version) as max_version'))
            ->groupBy('slug', 'asset_type')
            ->get();

        // Then get the actual records that match those max versions
        $latestIds = [];
        foreach ($maxVersions as $maxVersion) {
            $latest = SystemCategory::where('slug', $maxVersion->slug)
                ->where('asset_type', $assetType)
                ->where('version', $maxVersion->max_version)
                ->orderBy('id', 'desc') // If multiple records have same version, get the latest one
                ->first();
            
            if ($latest) {
                $latestIds[] = $latest->id;
            }
        }

        return SystemCategory::whereIn('id', $latestIds)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new system category template.
     *
     * @param array $data
     * @return SystemCategory
     */
    public function createTemplate(array $data): SystemCategory
    {
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
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $data['slug'] = $slug;

        // Set defaults
        $data['is_hidden'] = $data['is_hidden'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['version'] = $data['version'] ?? 1; // New templates start at version 1

        $template = SystemCategory::create($data);

        // Notify existing brands about the new system category
        // This makes it available for them to add on their brand edit page
        $this->notifyBrandsOfNewCategory($template);

        return $template;
    }

    /**
     * Update a system category template.
     * If updating the latest version, creates a new version instead of modifying the existing one.
     *
     * @param SystemCategory $systemCategory
     * @param array $data
     * @return SystemCategory
     */
    public function updateTemplate(SystemCategory $systemCategory, array $data): SystemCategory
    {
        // Check if this is the latest version
        if ($systemCategory->isLatestVersion()) {
            // Create a new version instead of updating
            return $this->createNewVersion($systemCategory, $data);
        }

        // For older versions, allow direct update (though this should rarely happen)
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
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $systemCategory->update($data);

        return $systemCategory->fresh();
    }

    /**
     * Create a new version of an existing system category template.
     * Clones the existing record, increments the version, and applies changes.
     * Does NOT touch existing brand categories.
     *
     * @param SystemCategory $existing
     * @param array $changes Array containing 'summary' and other fields to update
     * @return SystemCategory The new version
     */
    public function createNewVersion(SystemCategory $existing, array $changes): SystemCategory
    {
        // Find the maximum version for this slug/asset_type combination
        $maxVersion = SystemCategory::where('slug', $existing->slug)
            ->where('asset_type', $existing->asset_type)
            ->max('version') ?? 0;

        // Prepare data for new version
        $newVersionData = [
            'name' => $changes['name'] ?? $existing->name,
            'slug' => $existing->slug, // Keep same slug
            'icon' => $changes['icon'] ?? $existing->icon,
            'asset_type' => $existing->asset_type, // Keep same asset_type
            'is_hidden' => $changes['is_hidden'] ?? $existing->is_hidden,
            'sort_order' => $changes['sort_order'] ?? $existing->sort_order,
            'version' => $maxVersion + 1,
            'change_summary' => $changes['summary'] ?? $changes['change_summary'] ?? null,
        ];

        // Create the new version
        $newVersion = SystemCategory::create($newVersionData);

        // Detect and mark outdated categories
        $this->detectOutdatedCategories($newVersion);

        return $newVersion;
    }

    /**
     * Get upgrade statistics for a system category template.
     * Returns counts of brands with queued upgrades and brands that have already upgraded.
     *
     * @param SystemCategory $systemCategory
     * @return array{queued_upgrades: int, upgraded: int, total_brands: int}
     */
    public function getUpgradeStatistics(SystemCategory $systemCategory): array
    {
        // Get all system category IDs for this template (all versions with same slug/asset_type)
        $templateIds = SystemCategory::where('slug', $systemCategory->slug)
            ->where('asset_type', $systemCategory->asset_type)
            ->pluck('id')
            ->toArray();

        // Find all categories that match this system category:
        // 1. Categories linked via system_category_id (any version)
        // 2. Categories with matching slug and asset_type (legacy categories)
        $matchingCategories = \App\Models\Category::where(function ($query) use ($templateIds, $systemCategory) {
            $query->whereIn('system_category_id', $templateIds)
                ->orWhere(function ($q) use ($systemCategory) {
                    $q->where('slug', $systemCategory->slug)
                      ->where('asset_type', $systemCategory->asset_type->value);
                });
        })
        ->where('is_system', true)
        ->get();

        // Count unique brands
        $uniqueBrandIds = $matchingCategories->pluck('brand_id')->unique()->filter();

        // Count categories with upgrade_available = true (queued upgrades)
        $queuedUpgrades = $matchingCategories->where('upgrade_available', true)
            ->pluck('brand_id')
            ->unique()
            ->count();

        // Count categories that are already at the latest version (upgraded)
        $upgraded = $matchingCategories->where('system_version', $systemCategory->version)
            ->where('upgrade_available', false)
            ->pluck('brand_id')
            ->unique()
            ->count();

        // Total brands with this category
        $totalBrands = $uniqueBrandIds->count();

        return [
            'queued_upgrades' => $queuedUpgrades,
            'upgraded' => $upgraded,
            'total_brands' => $totalBrands,
        ];
    }

    /**
     * Detect categories that are outdated compared to the latest system category version.
     * Marks them with upgrade_available = true.
     *
     * @param SystemCategory $latest The latest system category version
     * @return int Number of categories marked as needing upgrade
     */
    public function detectOutdatedCategories(SystemCategory $latest): int
    {
        // Find all categories that need upgrading:
        // 1. Categories with system_category_id matching this template (any version)
        // 2. Categories with matching slug/asset_type (legacy categories not linked)
        // 3. Categories with system_version < latest.version

        // First, get all system category IDs for this template (same slug/asset_type)
        $templateIds = SystemCategory::where('slug', $latest->slug)
            ->where('asset_type', $latest->asset_type)
            ->pluck('id')
            ->toArray();

        // Find categories that need upgrading:
        // - Categories linked via system_category_id with version < latest
        // - Categories with matching slug/asset_type that are system categories
        $outdated = Category::where(function ($query) use ($templateIds, $latest) {
            // Categories linked via system_category_id
            $query->whereIn('system_category_id', $templateIds)
                ->where(function ($q) use ($latest) {
                    $q->whereNull('system_version')
                      ->orWhere('system_version', '<', $latest->version);
                });
        })
        ->orWhere(function ($query) use ($latest) {
            // Legacy categories with matching slug/asset_type (not linked via system_category_id)
            $query->where('slug', $latest->slug)
                  ->where('asset_type', $latest->asset_type->value)
                  ->where('is_system', true)
                  ->where(function ($q) use ($latest) {
                      $q->whereNull('system_category_id')
                        ->orWhereNull('system_version')
                        ->orWhere('system_version', '<', $latest->version);
                  });
        })
        ->get();

        // Mark them as needing upgrade
        $count = 0;
        foreach ($outdated as $category) {
            $category->update(['upgrade_available' => true]);
            $count++;
        }

        return $count;
    }

    /**
     * Delete a system category template.
     * Also soft-deletes all brand categories that were created from this template.
     *
     * @param SystemCategory $systemCategory
     * @return bool
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
        // This follows the same pattern as upgrades - user must accept the change
        foreach ($brandCategories as $category) {
            $category->update(['deletion_available' => true]);
        }

        // Delete the system category template itself
        return $systemCategory->delete();
    }

    /**
     * Sync system category templates to a brand.
     * Creates brand-specific categories from the templates (latest versions only).
     *
     * @param Brand $brand
     * @return void
     */
    public function syncToBrand(Brand $brand): void
    {
        $templates = $this->getAllTemplates();

        foreach ($templates as $template) {
            $this->addTemplateToBrand($brand, $template);
        }
    }

    /**
     * Add a single system category template to a brand.
     * Creates a brand-specific category from the template if it doesn't already exist.
     *
     * @param Brand $brand
     * @param SystemCategory $template
     * @return Category|null The created category, or null if it already exists
     */
    public function addTemplateToBrand(Brand $brand, SystemCategory $template): ?Category
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
                    'is_hidden' => $template->is_hidden,
                    'system_category_id' => $template->id,
                    'system_version' => $template->version,
                    'upgrade_available' => false,
                ]);
                return $existing->fresh();
            }
            return null;
        }

        // Create category from template (latest version)
        // Note: is_locked is set to true for system categories and is site admin only
        // Tenants cannot change is_locked - it can only be modified by site administrators
        return Category::create([
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'asset_type' => $template->asset_type,
            'name' => $template->name,
            'slug' => $template->slug,
            'icon' => $template->icon,
            'is_system' => true,
            'is_locked' => true, // System categories are locked by default (site admin only)
            'is_private' => false, // System categories are never private
            'is_hidden' => $template->is_hidden,
            'system_category_id' => $template->id,
            'system_version' => $template->version,
            'upgrade_available' => false,
        ]);
    }

    /**
     * Notify existing brands about a new system category.
     * This doesn't automatically create the category, but makes it available
     * for brands to add on their brand edit page.
     *
     * @param SystemCategory $newCategory The newly created system category
     * @return void
     */
    protected function notifyBrandsOfNewCategory(SystemCategory $newCategory): void
    {
        // For new system categories, we don't need to mark anything as needing upgrade
        // since they don't exist yet for any brands. They will show up as available
        // templates on the brand edit page for all brands.
        // This method is here for future extensibility if we need to track
        // which brands have been notified about new categories.
    }
}
