<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Str;

class CategoryService
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if tenant can create a category.
     */
    public function canCreate(Tenant $tenant, ?Brand $brand = null): bool
    {
        return $this->planService->canCreateCategory($tenant, $brand);
    }

    /**
     * Create a category with plan check.
     *
     * @throws PlanLimitExceededException
     */
    public function create(Tenant $tenant, Brand $brand, array $data): Category
    {
        // Check plan limit
        $this->planService->checkLimit('categories', $tenant, $brand);

        // Generate slug if not provided
        if (! isset($data['slug']) || empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Ensure slug is unique
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

        $data['tenant_id'] = $tenant->id;
        $data['brand_id'] = $brand->id;
        $data['is_system'] = false; // User-created categories are never system
        $data['is_locked'] = false; // User-created categories are never locked

        return Category::create($data);
    }

    /**
     * Update a category.
     *
     * @throws \Exception
     */
    public function update(Category $category, array $data): Category
    {
        // Prevent updates to locked/system categories
        if ($category->is_locked || $category->is_system) {
            throw new \Exception('Cannot update locked or system categories.');
        }

        // Generate slug if name changed and slug not provided
        if (isset($data['name']) && (! isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);

            // Ensure slug is unique (excluding current category)
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
     * @throws \Exception
     */
    public function delete(Category $category): void
    {
        // Prevent deletion of locked/system categories
        if ($category->is_locked || $category->is_system) {
            throw new \Exception('Cannot delete locked or system categories.');
        }

        $category->delete();
    }
}
