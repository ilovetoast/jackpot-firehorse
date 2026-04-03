<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;

/**
 * Category Policy
 *
 * Handles authorization for category operations.
 *
 * Visibility Rules:
 * - Public categories (is_private=false, is_hidden=false): Visible to all brand users
 * - Private categories (is_private=true): Require access via category access rules or 'view restricted categories' permission
 * - Hidden categories (is_hidden=true): Require 'manage categories' permission (or specific permission)
 *
 * Mutation Rules:
 * - System categories (is_system=true): Cannot be renamed, deleted, or have icon changed by tenants (admin template is source of truth)
 *   - Tenants may show/hide system categories (plan limits apply)
 *   - is_locked is site admin only (cannot be set/changed by tenants)
 * - Locked categories (is_locked=true): Cannot be updated or deleted (except is_hidden for system categories)
 *   - is_locked can only be set/changed by site administrators
 *   - Tenants cannot see or edit the is_locked field
 * - Custom categories: Can be updated/deleted by users with 'manage categories' permission
 *   - is_locked is site admin only (cannot be set/changed by tenants)
 */
class CategoryPolicy
{
    /**
     * Determine if the user can view any categories.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view brand'); // Users with view brand permission can view categories
    }

    /**
     * Determine if the user can view the category.
     *
     * Visibility is determined by:
     * - Tenant membership (user must belong to category's tenant)
     * - Brand assignment (user must be assigned to category's brand)
     * - Private flag (requires access via category access rules or 'view restricted categories' permission)
     * - Hidden flag (requires 'manage categories' permission)
     */
    public function view(User $user, Category $category): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($category->tenant_id)) {
            return false;
        }

        // User must be assigned to the brand
        if ($category->brand_id && ! $user->isAssignedToBrandId($category->brand_id)) {
            // Check if user is tenant admin/owner (they have access to all brands)
            $tenant = $this->resolveCategoryTenant($category);
            $tenantRole = $user->getRoleForTenant($tenant);
            if (! in_array($tenantRole, ['admin', 'owner'])) {
                return false;
            }
        }

        // If category is private, check category-level access rules
        if ($category->is_private) {
            // System categories cannot be private
            if ($category->is_system) {
                return true; // System categories are always accessible
            }

            // Check if user is tenant owner/admin or has 'view any restricted categories' permission
            // These users can bypass category access rules
            $tenant = $this->resolveCategoryTenant($category);
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

            // Also check brand-level owner/admin role
            $brand = $this->resolveCategoryBrand($category);
            $isBrandOwnerOrAdmin = false;
            if ($brand) {
                $brandRole = $user->getRoleForBrand($brand);
                $isBrandOwnerOrAdmin = in_array($brandRole, ['owner', 'admin']);
            }

            // Check for permission to view any restricted categories
            $canViewAnyRestricted = $user->hasPermissionForTenant($tenant, 'view.restricted.categories');

            // Owners/admins or users with permission can bypass access rules
            if ($isTenantOwnerOrAdmin || $isBrandOwnerOrAdmin || $canViewAnyRestricted) {
                return true;
            }

            // Otherwise, check if user has access via category_access rules
            if (! $category->userHasAccess($user)) {
                return false;
            }
        }

        // If category is hidden, user needs manage categories permission
        // This allows admins to see hidden categories while regular users cannot
        if ($category->is_hidden && ! $user->can('manage categories')) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can create categories.
     * Note: This is called from controller which has tenant context via middleware.
     * We'll check permission in the controller directly since we need tenant context.
     */
    public function create(User $user): bool
    {
        // Permission check is done in controller with tenant context
        // This method is kept for policy consistency but controller handles the actual check
        return true; // Controller will enforce the permission check
    }

    /**
     * Determine if the user can update the category.
     */
    public function update(User $user, Category $category): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($category->tenant_id)) {
            return false;
        }

        // Check brand-level permission (or tenant-level for admin/owner)
        $brand = $this->resolveCategoryBrand($category);
        $tenant = $this->resolveCategoryTenant($category);

        if ($brand) {
            // Check brand-level permission
            if (! $user->hasPermissionForBrand($brand, 'brand_categories.manage')
                && ! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        } else {
            // No brand assigned, check tenant-level permission
            if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        }

        // System categories: tenants may adjust visibility only (CategoryService enforces fields).
        if ($category->is_system) {
            return true;
        }

        // Cannot update locked custom categories
        // (System categories are handled above)
        if ($category->is_locked && ! $category->is_system) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can delete the category.
     *
     * System and locked categories cannot be deleted, even by admins.
     */
    public function delete(User $user, Category $category): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($category->tenant_id)) {
            return false;
        }

        // Check brand-level permission (or tenant-level for admin/owner)
        $brand = $this->resolveCategoryBrand($category);
        $tenant = $this->resolveCategoryTenant($category);

        if ($brand) {
            // Check brand-level permission
            if (! $user->hasPermissionForBrand($brand, 'brand_categories.manage')
                && ! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        } else {
            // No brand assigned, check tenant-level permission
            if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        }

        // Use the category's helper method to check if it can be deleted
        if (! $category->canBeDeleted()) {
            return false;
        }

        return true;
    }

    /**
     * Avoid lazy-loading tenant on Category (lazy loading is disabled app-wide).
     */
    private function resolveCategoryTenant(Category $category): ?Tenant
    {
        if ($category->tenant_id === null) {
            return null;
        }

        if ($category->relationLoaded('tenant')) {
            return $category->getRelation('tenant');
        }

        return Tenant::query()->find($category->tenant_id);
    }

    /**
     * Avoid lazy-loading brand on Category (lazy loading is disabled app-wide).
     */
    private function resolveCategoryBrand(Category $category): ?Brand
    {
        if ($category->brand_id === null) {
            return null;
        }

        if ($category->relationLoaded('brand')) {
            return $category->getRelation('brand');
        }

        return Brand::query()->find($category->brand_id);
    }
}
