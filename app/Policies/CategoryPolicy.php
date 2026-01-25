<?php

namespace App\Policies;

use App\Models\Category;
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
 * - System categories (is_system=true): Immutable - cannot be renamed, deleted, or have icon changed
 *   - Enterprise/Pro plans can hide system categories
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
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        // User must be assigned to the brand
        if ($category->brand_id && !$user->brands()->where('brands.id', $category->brand_id)->exists()) {
            // Check if user is tenant admin/owner (they have access to all brands)
            $tenant = $category->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);
            if (!in_array($tenantRole, ['admin', 'owner'])) {
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
            $tenant = $category->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            // Also check brand-level owner/admin role
            $brand = $category->brand;
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
            if (!$category->userHasAccess($user)) {
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
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        // Check brand-level permission (or tenant-level for admin/owner)
        $brand = $category->brand;
        $tenant = $category->tenant;
        
        if ($brand) {
            // Check brand-level permission
            if (!$user->hasPermissionForBrand($brand, 'brand_categories.manage')
                && !$user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        } else {
            // No brand assigned, check tenant-level permission
            if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        }

        // System categories can only be updated if:
        // 1. Plan has edit_system_categories feature, OR
        // 2. The system template no longer exists (orphaned category)
        if ($category->is_system) {
            // Check if template still exists
            $templateExists = false;
            if ($category->system_category_id) {
                $templateExists = \App\Models\SystemCategory::where('id', $category->system_category_id)->exists();
            } else {
                // Legacy category - check by slug/asset_type
                $templateExists = \App\Models\SystemCategory::where('slug', $category->slug)
                    ->where('asset_type', $category->asset_type->value)
                    ->exists();
            }
            
            // If template exists, require edit_system_categories feature
            if ($templateExists) {
                $planService = app(\App\Services\PlanService::class);
                $canEditSystem = $planService->hasFeature($tenant, 'edit_system_categories');
                
                // If plan allows editing system categories, allow updates even if locked
                // (CategoryService will handle which fields can be updated for locked categories)
                if ($canEditSystem) {
                    return true;
                }
                // Log why update was denied for debugging
                \Log::info('Category update denied', [
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'is_system' => $category->is_system,
                    'template_exists' => $templateExists,
                    'can_edit_system' => $canEditSystem,
                    'tenant_id' => $tenant->id,
                    'plan' => $planService->getCurrentPlan($tenant),
                ]);
                return false;
            }
            // Template is deleted, allow update
        }

        // Cannot update locked custom categories
        // (System categories are handled above)
        if ($category->is_locked && !$category->is_system) {
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
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        // Check brand-level permission (or tenant-level for admin/owner)
        $brand = $category->brand;
        $tenant = $category->tenant;
        
        if ($brand) {
            // Check brand-level permission
            if (!$user->hasPermissionForBrand($brand, 'brand_categories.manage')
                && !$user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        } else {
            // No brand assigned, check tenant-level permission
            if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
                return false;
            }
        }

        // Use the category's helper method to check if it can be deleted
        if (!$category->canBeDeleted()) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can upgrade the category.
     *
     * Only tenant admins/owners can upgrade system categories that have upgrades available.
     */
    public function upgrade(User $user, Category $category): bool
    {
        // User must belong to the tenant
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        // User must have brand_categories.manage permission
        $tenant = $category->tenant;
        if (! $user->hasPermissionForTenant($tenant, 'brand_categories.manage')) {
            return false;
        }

        // Category must be a system category
        if (! $category->is_system) {
            return false;
        }

        // Category must have upgrade available
        if (! $category->upgrade_available) {
            return false;
        }

        return true;
    }
}
