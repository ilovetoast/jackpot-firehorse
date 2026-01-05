<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
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
     * Get all system category templates.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllTemplates()
    {
        return SystemCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Get templates by asset type.
     *
     * @param AssetType $assetType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTemplatesByAssetType(AssetType $assetType)
    {
        return SystemCategory::where('asset_type', $assetType)
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

        // Ensure slug is unique within asset_type
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
        $data['is_private'] = $data['is_private'] ?? false;
        $data['is_hidden'] = $data['is_hidden'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return SystemCategory::create($data);
    }

    /**
     * Update a system category template.
     *
     * @param SystemCategory $systemCategory
     * @param array $data
     * @return SystemCategory
     */
    public function updateTemplate(SystemCategory $systemCategory, array $data): SystemCategory
    {
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
     * Delete a system category template.
     *
     * @param SystemCategory $systemCategory
     * @return bool
     */
    public function deleteTemplate(SystemCategory $systemCategory): bool
    {
        // Note: Existing brand categories created from this template will remain
        // This only deletes the template, affecting only future brands
        return $systemCategory->delete();
    }

    /**
     * Sync system category templates to a brand.
     * Creates brand-specific categories from the templates.
     *
     * @param Brand $brand
     * @return void
     */
    public function syncToBrand(Brand $brand): void
    {
        $templates = $this->getAllTemplates();

        foreach ($templates as $template) {
            // Check if category already exists for this brand
            $existing = Category::where('tenant_id', $brand->tenant_id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', $template->asset_type)
                ->where('slug', $template->slug)
                ->first();

            if (! $existing) {
                // Create category from template
                Category::create([
                    'tenant_id' => $brand->tenant_id,
                    'brand_id' => $brand->id,
                    'asset_type' => $template->asset_type,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'is_system' => true,
                    'is_locked' => true, // System categories are locked
                    'is_private' => $template->is_private,
                    'is_hidden' => $template->is_hidden,
                ]);
            }
        }
    }
}
