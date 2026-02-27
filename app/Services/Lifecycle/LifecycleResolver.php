<?php

namespace App\Services\Lifecycle;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle Resolver Service
 * 
 * SINGLE SOURCE OF TRUTH for asset lifecycle visibility logic.
 * 
 * This service unifies lifecycle filtering logic that was previously duplicated
 * between AssetController and DeliverableController.
 * 
 * Responsibilities:
 * - Apply lifecycle filters to queries based on state and permissions
 * - Enforce permission-based access control for lifecycle states
 * - Provide consistent default behavior (published only)
 * - Support all lifecycle states: pending_approval, pending_publication, unpublished, archived, expired
 * 
 * Rules:
 * - null state = default behavior (published only, excludes unpublished)
 * - Explicit states require corresponding permissions
 * - Permission checks are explicit, never implicit
 * - No controller or model should reason about lifecycle directly
 * 
 * Usage:
 * ```php
 * $resolver = app(LifecycleResolver::class);
 * $query = $resolver->apply(
 *     $assetsQuery,
 *     $request->get('lifecycle'),
 *     $user,
 *     $tenant,
 *     $brand
 * );
 * ```
 */
class LifecycleResolver
{
    public function __construct(
        protected TenantPermissionResolver $permissionResolver
    ) {
    }

    /**
     * Apply lifecycle filtering to a query.
     * 
     * @param Builder $query The asset query builder
     * @param string|null $lifecycleState The lifecycle filter state (pending_approval, pending_publication, unpublished, archived, expired, or null for default)
     * @param User|null $user The current user
     * @param Tenant $tenant The current tenant
     * @param Brand $brand The current brand
     * @return Builder The modified query builder
     */
    public function apply(
        Builder $query,
        ?string $lifecycleState,
        ?User $user,
        Tenant $tenant,
        Brand $brand
    ): Builder {
        // Step 1: Validate permissions and normalize lifecycle state
        $normalizedState = $this->validateAndNormalizeState($lifecycleState, $user, $tenant, $brand);
        
        
        // Step 2: Apply lifecycle-specific filters
        $this->applyLifecycleFilter($query, $normalizedState, $user, $tenant, $brand);
        
        // Step 3: Apply default exclusions (archived, expired) unless explicitly filtered
        $this->applyDefaultExclusions($query, $normalizedState);
        
        return $query;
    }

    /**
     * Return normalized lifecycle state (or null if invalid/unauthorized).
     * Phase B2: Used by controller to decide deleted_at condition before apply().
     */
    public function normalizeState(?string $lifecycleState, ?User $user, Tenant $tenant, Brand $brand): ?string
    {
        return $this->validateAndNormalizeState($lifecycleState, $user, $tenant, $brand);
    }
    
    /**
     * Validate lifecycle state against user permissions and normalize.
     * 
     * Returns null if state is invalid or user lacks permission.
     * Logs security warnings for unauthorized access attempts.
     * 
     * @param string|null $state The requested lifecycle state
     * @param User|null $user The current user
     * @param Tenant $tenant The current tenant
     * @param Brand $brand The current brand
     * @return string|null The normalized state, or null if invalid/unauthorized
     */
    protected function validateAndNormalizeState(
        ?string $state,
        ?User $user,
        Tenant $tenant,
        Brand $brand
    ): ?string {
        if ($state === null) {
            return null; // Default behavior
        }
        
        // Check permissions for each lifecycle state
        // CRITICAL: Use TenantPermissionResolver (canonical permission check)
        // This ensures PermissionMap is checked FIRST, then Spatie roles
        $canPublish = $this->permissionResolver->has($user, $tenant, 'asset.publish');
        $canBypassApproval = $this->permissionResolver->has($user, $tenant, 'metadata.bypass_approval');
        $canArchive = $this->permissionResolver->has($user, $tenant, 'asset.archive') ||
            ($brand && $this->permissionResolver->hasForBrand($user, $brand, 'asset.archive'));
        // Phase B2: Trash view – Tenant Admin or Brand Manager only
        $canViewTrash = $user && (
            in_array($user->getRoleForTenant($tenant), ['admin', 'owner'], true) ||
            ($brand && $user->hasPermissionForBrand($brand, 'assets.delete'))
        );

        // Validate state-specific permissions
        if ($state === 'deleted' && !$canViewTrash) {
            Log::warning('[LifecycleResolver] Unauthorized deleted (trash) filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_state' => $state,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            return null;
        }
        if ($state === 'pending_approval' && !$canPublish) {
            Log::warning('[LifecycleResolver] Unauthorized pending_approval filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_state' => $state,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            return null; // Reset to default
        }
        
        if ($state === 'pending_publication') {
            // No additional permission check - visibility rules handle access control
            return $state;
        }
        
        if ($state === 'unpublished' && !$canBypassApproval) {
            Log::warning('[LifecycleResolver] Unauthorized unpublished filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_state' => $state,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            return null; // Reset to default
        }
        
        if ($state === 'archived' && !$canArchive) {
            Log::warning('[LifecycleResolver] Unauthorized archived filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_state' => $state,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            return null; // Reset to default
        }
        
        if ($state === 'expired' && !$canArchive) {
            Log::warning('[LifecycleResolver] Unauthorized expired filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_state' => $state,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            return null; // Reset to default
        }
        
        return $state;
    }
    
    /**
     * Apply lifecycle-specific filter to the query.
     * 
     * @param Builder $query The query builder
     * @param string|null $state The normalized lifecycle state
     * @param User|null $user The current user
     * @param Tenant $tenant The current tenant
     * @param Brand $brand The current brand
     */
    protected function applyLifecycleFilter(
        Builder $query,
        ?string $state,
        ?User $user,
        Tenant $tenant,
        Brand $brand
    ): void {
        $canPublish = $user && $user->hasPermissionForTenant($tenant, 'asset.publish');
        $canBypassApproval = $user && $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval');
        $canArchive = $user && (
            $user->hasPermissionForTenant($tenant, 'asset.archive') ||
            ($brand && $user->hasPermissionForBrand($brand, 'asset.archive'))
        );
        $canSeeUnpublished = $canPublish || $canBypassApproval;
        
        if ($state === 'pending_approval') {
            // Pending approval: Only HIDDEN status, unpublished
            // Permission check already done in validateAndNormalizeState - if we get here, user has permission
            $query->where('status', AssetStatus::HIDDEN)
                ->whereNull('published_at');
            
            Log::info('[LifecycleResolver] Applied pending_approval lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
        } elseif ($state === 'pending_publication') {
            // Phase J: Pending Publication - Show assets with approval_status = pending or rejected
            // Visibility rules:
            // - Contributors: Only their own pending/rejected assets
            // - Admin/Owner/Brand Manager: All pending/rejected assets
            $userRole = $user ? $user->getRoleForTenant($tenant) : null;
            $isTenantOwnerOrAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);
            
            // Check if user is a brand manager
            $isBrandManager = false;
            if ($user && $brand) {
                $membership = $user->activeBrandMembership($brand);
                $isBrandManager = $membership && ($membership['role'] ?? null) === 'brand_manager';
            }
            
            // Check if user is a contributor
            $isContributor = false;
            if ($user && $brand) {
                $membership = $user->activeBrandMembership($brand);
                $isContributor = $membership && ($membership['role'] ?? null) === 'contributor';
            }
            
            // Approvers (Admin/Owner/Brand Manager) see all pending/rejected assets
            // Contributors see only their own pending/rejected assets
            if ($isContributor && !$isTenantOwnerOrAdmin && !$isBrandManager) {
                // Contributor: Only their own assets
                $query->where('user_id', $user->id);
            }
            // Admin/Owner/Brand Manager: No user_id filter (see all)
            
            // Filter by approval_status = pending or rejected
            $query->where(function ($q) {
                $q->where('approval_status', ApprovalStatus::PENDING)
                  ->orWhere('approval_status', ApprovalStatus::REJECTED);
            });
            
            Log::info('[LifecycleResolver] Applied pending_publication lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'is_contributor' => $isContributor,
                'is_approver' => $isTenantOwnerOrAdmin || $isBrandManager,
            ]);
        } elseif ($state === 'unpublished') {
            // Unpublished filter: Show all unpublished assets (VISIBLE, HIDDEN, FAILED)
            // Permission check already done in validateAndNormalizeState - if we get here, user has permission
            // CRITICAL: Use withoutGlobalScopes() to ensure no global scope interferes
            // This is defensive - if a global scope exists, it will be bypassed
            $query->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN, AssetStatus::FAILED])
                ->whereNull('published_at');
            
            Log::info('[LifecycleResolver] Applied unpublished lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_bypass_approval' => $canBypassApproval,
            ]);
        } elseif ($state === 'archived') {
            // Archived filter: Show only archived assets
            // Permission check already done in validateAndNormalizeState - if we get here, user has permission
            $query->whereNotNull('archived_at');
            
            Log::info('[LifecycleResolver] Applied archived lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_archive' => $canArchive,
            ]);
        } elseif ($state === 'expired') {
            // Phase M: Expired filter: Show only expired assets
            // Permission check already done in validateAndNormalizeState - if we get here, user has permission
            $query->whereNotNull('expires_at')
                  ->where('expires_at', '<=', now());
            
            Log::info('[LifecycleResolver] Applied expired lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_archive' => $canArchive,
            ]);
        } elseif ($state === 'deleted') {
            // Phase B2: Trash view – deleted_at already set by controller (whereNotNull)
            // No additional status/published filters; show all soft-deleted assets
        } else {
            // Default visibility rules (no lifecycle filter active)
            // CRITICAL: Unpublished assets should NEVER show unless filter is explicitly active
            // Even users with permissions should not see unpublished assets by default
            // RULE: Uploaded assets must NEVER disappear from grid - only hide if: unpublished, archived, deleted, pending approval
            // FAILED assets (processing errors) must remain visible so users can see, retry, or download originals
            if ($canSeeUnpublished) {
                // Users with permissions can see HIDDEN and FAILED assets (pending approval, processing errors) that are published
                $query->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN, AssetStatus::FAILED])
                    ->whereNotNull('published_at'); // Always exclude unpublished in default view
            } else {
                // Regular users see VISIBLE and FAILED (failed assets must never disappear)
                $query->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::FAILED])
                    ->whereNotNull('published_at'); // Phase L.2: Exclude unpublished assets
            }
        }
    }
    
    /**
     * Apply default exclusions (archived, expired) unless explicitly filtered.
     * 
     * @param Builder $query The query builder
     * @param string|null $state The normalized lifecycle state
     */
    protected function applyDefaultExclusions(Builder $query, ?string $state): void
    {
        // Phase L.3: Exclude archived assets by default (unless archived filter is active)
        // Phase B2: In trash view, do not exclude by archived/expired – show all deleted
        if ($state === 'deleted') {
            return;
        }
        if ($state !== 'archived') {
            $query->whereNull('archived_at');
        }
        
        // Phase M: Exclude expired assets by default
        // Expired assets are hidden from the grid unless explicitly filtered
        // Note: Expired filter is handled above in lifecycle filter section
        if ($state !== 'expired') {
            $query->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
        }
        
        // Phase AF-1: Exclude pending and rejected assets from main grid
        // CRITICAL: Only exclude pending/rejected assets if they are ALSO unpublished
        // Published assets (published_at != null) should appear in default view regardless of approval_status
        // Publication state (published_at) determines visibility, not approval state
        // Note: This applies to default view only - lifecycle filter handles pending/rejected separately
        // IMPORTANT: Do NOT apply this exclusion for 'unpublished' filter - unpublished assets
        // may have any approval_status and should all be visible
        if ($state !== 'pending_publication' && $state !== 'unpublished') {
            $query->where(function ($q) {
                // Include assets that are:
                // 1. Not pending/rejected (approved or not_required)
                // 2. OR published (published_at != null) - published assets are visible regardless of approval_status
                $q->where(function ($subQ) {
                    $subQ->where('approval_status', 'not_required')
                         ->orWhere('approval_status', 'approved')
                         ->orWhereNull('approval_status'); // Handle legacy assets without approval_status
                })
                ->orWhereNotNull('published_at'); // Published assets are visible even if approval is pending/rejected
            });
        }
    }
}
