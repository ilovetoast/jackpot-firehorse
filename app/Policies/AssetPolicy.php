<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Support\Roles\PermissionMap;

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
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }

        // User must be assigned to the brand (or be tenant admin/owner), or have collection-only access to a collection containing this asset
        if ($asset->brand_id) {
            $tenant = $asset->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);

            // Tenant admins/owners have access to all brands
            if (in_array($tenantRole, ['admin', 'owner'])) {
                return true;
            }

            // Phase MI-1: Check active brand membership
            if ($user->activeBrandMembership($asset->brand)) {
                return true;
            }

            // C12: Collection-only access — user can view asset if it is in a collection they have an accepted grant for
            $accessibleCollectionIds = $user->collectionAccessGrants()
                ->whereNotNull('accepted_at')
                ->pluck('collection_id');
            if ($accessibleCollectionIds->isNotEmpty() && $asset->collections()->whereIn('collections.id', $accessibleCollectionIds)->exists()) {
                return true;
            }

            return false;
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
     * Determine if user can request full PDF extraction.
     */
    public function requestFullPdfExtraction(User $user, Asset $asset): bool
    {
        if (!$this->view($user, $asset)) {
            return false;
        }

        $tenantRole = $user->getRoleForTenant($asset->tenant);

        return in_array($tenantRole, ['owner', 'admin'], true);
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
     *
     * - Admins and brand managers: can delete any asset (assets.delete)
     * - Managers: can delete only their own files (assets.delete_own + asset.user_id match)
     * - Contributors and viewers: cannot delete
     */
    public function delete(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }

        $tenant = $asset->tenant;

        // Admins and brand managers: full delete permission
        if ($user->hasPermissionForTenant($tenant, 'assets.delete')) {
            return true;
        }
        if ($asset->brand_id && $user->hasPermissionForBrand($asset->brand, 'assets.delete')) {
            return true;
        }

        // Managers: can delete only their own files
        if ($user->hasPermissionForTenant($tenant, 'assets.delete_own')) {
            return $asset->user_id !== null && (int) $asset->user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine if the user can publish the asset.
     *
     * Phase L.2 — Asset Publication
     * Users can publish assets if they:
     * - Belong to the tenant
     * - Have 'asset.publish' permission for the tenant
     * - Asset is not archived
     * - Asset is not failed
     */
    public function publish(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }

        // Check permission for publishing assets
        $tenant = $asset->tenant;
        
        // Check tenant-level permission first
        $hasTenantPermission = $user->hasPermissionForTenant($tenant, 'asset.publish');
        
        // If no tenant permission, check brand-level permission (if asset has a brand)
        if (!$hasTenantPermission && $asset->brand_id) {
            $hasTenantPermission = $user->hasPermissionForBrand($asset->brand, 'asset.publish');
        }
        
        if (!$hasTenantPermission) {
            return false;
        }

        // Cannot publish archived assets
        if ($asset->isArchived()) {
            return false;
        }

        // Cannot publish failed assets
        if ($asset->status === \App\Enums\AssetStatus::FAILED) {
            return false;
        }

        // Brand/tenant scoping: User must be assigned to the brand (or be tenant admin/owner)
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenantRole = $user->getRoleForTenant($tenant);
            
            // Tenant admins/owners have access to all brands
            if (!in_array($tenantRole, ['admin', 'owner'])) {
                // Phase MI-1: Check active brand membership
                if (!$user->activeBrandMembership($asset->brand)) {
                    return false;
                }
            }
        }

        // Phase J.3.1: Contributors cannot publish assets when approval is enabled
        // Check if brand requires contributor approval
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            // Check brand role
            $membership = $user->activeBrandMembership($brand);
            $brandRole = $membership['role'] ?? null;
            $isContributor = $brandRole === 'contributor' && !$isTenantOwnerOrAdmin;
            
            // If brand requires contributor approval, contributors cannot publish
            if ($isContributor && $brand->requiresContributorApproval()) {
                return false;
            }
        }
        
        // Phase J.3.1: Contributors cannot publish assets with approval_status = pending
        // Only approvers (owner, admin, brand_manager) can publish pending assets
        if ($asset->approval_status === \App\Enums\ApprovalStatus::PENDING) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            // Check brand role if asset has a brand
            $brandRole = null;
            if ($asset->brand_id) {
                $membership = $user->activeBrandMembership($asset->brand);
                $brandRole = $membership['role'] ?? null;
            }
            
            // Only allow if user is an approver (owner, admin, or brand_manager)
            // Contributors cannot bypass the approval workflow
            $isApprover = $isTenantOwnerOrAdmin || 
                         ($brandRole && PermissionMap::canApproveAssets($brandRole));
            
            if (!$isApprover) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the user can unpublish the asset.
     *
     * Phase L.2 — Asset Publication
     * Users can unpublish assets if they:
     * - Belong to the tenant
     * - Have 'asset.unpublish' permission for the tenant
     */
    public function unpublish(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }

        // Check permission for unpublishing assets
        $tenant = $asset->tenant;
        
        // Check tenant-level permission first
        $hasTenantPermission = $user->hasPermissionForTenant($tenant, 'asset.unpublish');
        
        // If no tenant permission, check brand-level permission (if asset has a brand)
        if (!$hasTenantPermission && $asset->brand_id) {
            $hasTenantPermission = $user->hasPermissionForBrand($asset->brand, 'asset.unpublish');
        }
        
        if (!$hasTenantPermission) {
            return false;
        }

        // Brand/tenant scoping: User must be assigned to the brand (or be tenant admin/owner)
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenantRole = $user->getRoleForTenant($tenant);
            
            // Tenant admins/owners have access to all brands
            if (!in_array($tenantRole, ['admin', 'owner'])) {
                // Phase MI-1: Check active brand membership
                if (!$user->activeBrandMembership($asset->brand)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if the user can archive the asset.
     *
     * Phase L.3 — Asset Archive & Restore
     * Users can archive assets if they:
     * - Belong to the tenant
     * - Have 'asset.archive' permission for the tenant
     * - Asset is not failed
     */
    public function archive(User $user, Asset $asset): bool
    {
        // User must belong to the tenant
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }

        // Check permission for archiving assets
        $tenant = $asset->tenant;
        
        // Check tenant-level permission first
        $hasTenantPermission = $user->hasPermissionForTenant($tenant, 'asset.archive');
        
        // If no tenant permission, check brand-level permission (if asset has a brand)
        if (!$hasTenantPermission && $asset->brand_id) {
            $hasTenantPermission = $user->hasPermissionForBrand($asset->brand, 'asset.archive');
        }
        
        if (!$hasTenantPermission) {
            return false;
        }

        // Cannot archive failed assets
        if ($asset->status === \App\Enums\AssetStatus::FAILED) {
            return false;
        }

        // Phase J.3.1: Contributors cannot archive assets when approval is enabled
        // Check if brand requires contributor approval
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            // Check brand role
            $membership = $user->activeBrandMembership($brand);
            $brandRole = $membership['role'] ?? null;
            $isContributor = $brandRole === 'contributor' && !$isTenantOwnerOrAdmin;
            
            // If brand requires contributor approval, contributors cannot archive
            if ($isContributor && $brand->requiresContributorApproval()) {
                return false;
            }
        }

        // Brand/tenant scoping: User must be assigned to the brand (or be tenant admin/owner)
        if ($asset->brand_id) {
            $brand = $asset->brand;
            $tenantRole = $user->getRoleForTenant($tenant);
            
            // Tenant admins/owners have access to all brands
            if (!in_array($tenantRole, ['admin', 'owner'])) {
                // Phase MI-1: Check active brand membership
                if (!$user->activeBrandMembership($asset->brand)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if the user can restore a previous file version as a new current version.
     *
     * Phase 5D — Version Restore: Tenant admin/owner only (same as other admin actions).
     */
    public function restoreVersion(User $user, Asset $asset): bool
    {
        if (!$this->view($user, $asset)) {
            return false;
        }
        $tenant = $asset->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);

        return in_array($tenantRole, ['admin', 'owner']);
    }

    /**
     * Determine if the user can restore an archived asset.
     *
     * Phase L.3 — Archive Restore: Editor+ (asset.restore permission).
     * Note: Use asset.update when that permission exists for finer-grained control.
     */
    public function restoreArchive(User $user, Asset $asset): bool
    {
        return $user->can('asset.restore');
    }

    /**
     * Phase B2: Determine if the user can view trash (deleted assets).
     * Tenant Admin + Brand Manager only. Contributors and Viewers blocked.
     */
    public function viewTrash(User $user, ?Asset $asset = null): bool
    {
        if (! $user) {
            return false;
        }
        $tenant = $asset?->tenant ?? app('tenant');
        if (! $tenant || ! $user->belongsToTenant($tenant->id)) {
            return false;
        }
        if (in_array($user->getRoleForTenant($tenant), ['admin', 'owner'], true)) {
            return true;
        }
        $brand = $asset?->brand ?? (app()->bound('brand') ? app('brand') : null);
        if ($brand && $user->hasPermissionForBrand($brand, 'assets.delete')) {
            return true;
        }
        return false;
    }

    /**
     * Phase B2: Permanently delete (force delete) from trash. Tenant Admin only.
     */
    public function forceDelete(User $user, Asset $asset): bool
    {
        if (! $user->belongsToTenant($asset->tenant_id)) {
            return false;
        }
        $tenant = $asset->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);

        return in_array($tenantRole, ['admin', 'owner'], true);
    }
}
