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
     * Delete a brand with proper cleanup.
     * 
     * When a brand is deleted:
     * - All users are detached from the brand (brand_user pivot table entries are deleted)
     * - All brand invitations are deleted (via cascadeOnDelete)
     * - All categories are deleted (via cascadeOnDelete)
     * - All assets and their original files are deleted (via cascadeOnDelete if assets belong to brand)
     * - All activity events referencing this brand are set to null (via onDelete('set null'))
     * - Users remain in the company/tenant (not detached from tenant)
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

        // Detach all users from the brand before deletion
        // This removes entries from brand_user pivot table
        // Note: Users remain in the tenant (tenant_user relationship is not affected)
        $brand->users()->detach();

        // Delete brand logo file if it exists
        if ($brand->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($brand->logo_path);
        }

        // Delete assets associated with this brand
        // Note: If assets table exists and has brand_id foreign key with cascadeOnDelete,
        // this will be handled automatically. If assets need manual cleanup (e.g., S3 files),
        // add that logic here in the future.
        // TODO: Implement asset file deletion from S3 when asset management is fully implemented

        // Delete the brand
        // Cascades will handle:
        // - Categories (cascadeOnDelete)
        // - Brand invitations (cascadeOnDelete)
        // - Brand-user pivot entries (handled manually above)
        // Activity events will have brand_id set to null (onDelete('set null'))
        $brand->delete();
    }
}
