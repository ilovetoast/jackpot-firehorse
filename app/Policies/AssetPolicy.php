<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

/**
 * Asset Policy
 *
 * Handles authorization for asset operations.
 */
class AssetPolicy
{
    /**
     * Determine if the user can view any assets.
     */
    public function viewAny(User $user): bool
    {
        // Permission check is done in controller with tenant context
        return true;
    }

    /**
     * Determine if the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->tenants()->where('tenants.id', $asset->tenant_id)->exists()) {
            return false;
        }

        // User must be assigned to the brand (or be tenant admin/owner)
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenant = $asset->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);
            
            // Tenant admins/owners have access to all brands
            if (!in_array($tenantRole, ['admin', 'owner'])) {
                if (!$user->brands()->where('brands.id', $asset->brand_id)->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if the user can retry thumbnail generation for the asset.
     *
     * Users can retry thumbnails if they:
     * - Can view the asset
     * - Have 'assets.retry_thumbnails' permission for the tenant
     *
     * Future: Admin override can be added here (gate hook for admin bypass).
     *
     * IMPORTANT: This feature respects the locked thumbnail pipeline:
     * - Does not modify existing GenerateThumbnailsJob
     * - Does not mutate Asset.status
     */
    public function retryThumbnails(User $user, Asset $asset): bool
    {
        // User must be able to view the asset
        if (!$this->view($user, $asset)) {
            return false;
        }

        // Check permission for retrying thumbnails
        $tenant = $asset->tenant;
        if (! $user->hasPermissionForTenant($tenant, 'assets.retry_thumbnails')) {
            return false;
        }

        // Future: Admin override can be added here
        // Example: if (Gate::allows('admin.override.retry_limits')) { return true; }

        return true;
    }

    /**
     * Determine if the user can regenerate thumbnails with admin privileges.
     *
     * Site roles (site_owner, site_admin, site_support, site_engineering) can:
     * - Regenerate specific thumbnail styles
     * - Troubleshoot thumbnail issues
     * - Test new file types
     *
     * Requires 'assets.regenerate_thumbnails_admin' permission (site role only).
     */
    public function regenerateThumbnailsAdmin(User $user, Asset $asset): bool
    {
        // User must be able to view the asset
        if (!$this->view($user, $asset)) {
            return false;
        }

        // Check for site role (admin permission is site-scoped, not tenant-scoped)
        // Site roles have this permission globally for troubleshooting
        $siteRoles = $user->getSiteRoles();
        if (empty($siteRoles)) {
            return false;
        }

        // Check if user has the admin regeneration permission
        // Note: This is a global permission for site roles, not tenant-scoped
        return $user->hasPermissionTo('assets.regenerate_thumbnails_admin');
    }

    /**
     * Determine if the user can delete the asset.
     */
    public function delete(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->tenants()->where('tenants.id', $asset->tenant_id)->exists()) {
            return false;
        }

        // Check permission for deleting assets
        $tenant = $asset->tenant;
        if (! $user->hasPermissionForTenant($tenant, 'assets.delete')) {
            return false;
        }

        return true;
    }
}
