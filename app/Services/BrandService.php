<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Support\Str;

class BrandService
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Check if tenant can create a brand.
     */
    public function canCreate(Tenant $tenant): bool
    {
        return $this->planService->canCreateBrand($tenant);
    }

    /**
     * Create a brand with plan check.
     *
     * @throws PlanLimitExceededException
     */
    public function create(Tenant $tenant, array $data): Brand
    {
        // Check plan limit
        $this->planService->checkLimit('brands', $tenant);

        // Generate slug if not provided
        if (! isset($data['slug']) || empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Ensure slug is unique
        $baseSlug = $data['slug'];
        $slug = $baseSlug;
        $counter = 1;
        while (Brand::where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $data['slug'] = $slug;

        $data['tenant_id'] = $tenant->id;
        
        // Default show_in_selector to true if not provided
        if (!isset($data['show_in_selector'])) {
            $data['show_in_selector'] = true;
        }

        return Brand::create($data);
    }

    /**
     * Update a brand.
     */
    public function update(Brand $brand, array $data): Brand
    {
        // Generate slug if name changed and slug not provided
        if (isset($data['name']) && (! isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);

            // Ensure slug is unique (excluding current brand)
            $baseSlug = $data['slug'];
            $slug = $baseSlug;
            $counter = 1;
            while (Brand::where('tenant_id', $brand->tenant_id)
                ->where('slug', $slug)
                ->where('id', '!=', $brand->id)
                ->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $brand->update($data);

        return $brand->fresh();
    }

    /**
     * Delete a brand.
     *
     * @throws \Exception
     */
    public function delete(Brand $brand): void
    {
        // Prevent deletion if it's the only brand
        $brandCount = Brand::where('tenant_id', $brand->tenant_id)->count();
        if ($brandCount <= 1) {
            throw new \Exception('Cannot delete the only brand for a tenant.');
        }

        // Prevent deletion if it's the default brand
        if ($brand->is_default) {
            // Make another brand default first
            $otherBrand = Brand::where('tenant_id', $brand->tenant_id)
                ->where('id', '!=', $brand->id)
                ->first();

            if ($otherBrand) {
                $otherBrand->update(['is_default' => true]);
            }
        }

        $brand->delete();
    }
}
