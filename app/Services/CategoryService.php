<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Category Service
 *
 * Handles business logic for category creation, updates, and deletion.
 * Ensures plan limits are enforced and system categories are protected.
 */
class CategoryService
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if tenant can create a category for a brand.
     */
    public function canCreate(Tenant $tenant, Brand $brand): bool
    {
        return $this->planService->canCreateCategory($tenant, $brand);
    }

    /**
     * Create a category with plan check.
     *
     * Creates a custom (non-system) category for a brand.
     * System categories should only be created via SystemCategorySeeder.
     *
     * @param Tenant $tenant The tenant/company
     * @param Brand $brand The brand to create the category for
     * @param array $data Category data (name, slug, asset_type, is_private, etc.)
     * @return Category The created category
     * @throws PlanLimitExceededException If plan limit is exceeded
     */
    public function create(Tenant $tenant, Brand $brand, array $data): Category
    {
        // Check plan limit
        $this->planService->checkLimit('categories', $tenant, $brand);

        // Generate slug if not provided
        if (! isset($data['slug']) || empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Ensure slug is unique within tenant/brand/asset_type scope
        $baseSlug = $data['slug'];
        $slug = $baseSlug;
        $counter = 1;
        while (Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', $data['asset_type'])
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $data['slug'] = $slug;

        // Set required fields
        $data['tenant_id'] = $tenant->id;
        $data['brand_id'] = $brand->id;
        $data['is_system'] = false; // User-created categories are never system
        $data['is_locked'] = false; // User-created categories are never locked
        $data['is_hidden'] = $data['is_hidden'] ?? false; // Default to visible
        
        // Auto-assign icon if not provided
        if (!isset($data['icon']) || empty($data['icon'])) {
            $data['icon'] = \App\Helpers\CategoryIcons::getDefaultIcon($data['name'], $data['slug']);
        }

        return Category::create($data);
    }

    /**
     * Update a category.
     *
     * Updates a custom category. System categories can only be updated if plan allows.
     *
     * @param Category $category The category to update
     * @param array $data Updated data
     * @return Category The updated category
     * @throws \Exception If category is locked or system (without plan permission)
     */
    public function update(Category $category, array $data): Category
    {
        // Prevent updates to locked categories
        if ($category->is_locked) {
            throw new \Exception('Cannot update locked categories.');
        }

        // System categories can only be updated if plan has edit_system_categories feature
        if ($category->is_system) {
            $tenant = $category->tenant;
            if (! $this->planService->hasFeature($tenant, 'edit_system_categories')) {
                throw new \Exception('Cannot update system categories. Upgrade to Pro or Enterprise plan to edit system categories.');
            }
        }

        // Prevent changing is_system flag
        if (isset($data['is_system'])) {
            unset($data['is_system']);
        }

        // Generate slug if name changed and slug not provided
        if (isset($data['name']) && (! isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);

            // Ensure slug is unique within tenant/brand/asset_type scope (excluding current category)
            $baseSlug = $data['slug'];
            $slug = $baseSlug;
            $counter = 1;
            while (Category::where('tenant_id', $category->tenant_id)
                ->where('brand_id', $category->brand_id)
                ->where('asset_type', $category->asset_type)
                ->where('slug', $slug)
                ->where('id', '!=', $category->id)
                ->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $category->update($data);

        return $category->fresh();
    }

    /**
     * Delete a category.
     *
     * Deletes a custom category. System categories cannot be deleted.
     *
     * @param Category $category The category to delete
     * @return void
     * @throws \Exception If category is locked or system
     */
    public function delete(Category $category): void
    {
        // Prevent deletion of locked/system categories
        if ($category->is_locked || $category->is_system) {
            throw new \Exception('Cannot delete locked or system categories.');
        }

        $category->delete();
    }

    /**
     * Toggle the hidden state of a category.
     *
     * Allows hiding/showing categories. System categories can be hidden
     * but this should be plan/permission gated in the controller.
     *
     * @param Category $category The category to toggle
     * @param bool $hidden Whether to hide or show the category
     * @return Category The updated category
     * @throws \Exception If category is locked and trying to change hidden state
     */
    public function setHidden(Category $category, bool $hidden): Category
    {
        // System categories can be hidden, but locked categories cannot be modified
        if ($category->is_locked && $category->is_hidden !== $hidden) {
            throw new \Exception('Cannot change hidden state of locked categories.');
        }

        $category->update(['is_hidden' => $hidden]);

        return $category->fresh();
    }
}
