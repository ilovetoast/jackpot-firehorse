<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\User;
use App\Support\Roles\RoleRegistry;

/**
 * Collection Policy (Collections C2 base; C6 extends view for visibility/membership).
 *
 * C2: create/update/delete/manageAssets/addAsset/removeAsset are locked.
 * C6: view() considers collection visibility (brand / restricted / private) and membership.
 */
class CollectionPolicy
{
    /**
     * Determine if the user can view the collection (C6: visibility + membership).
     * C12: Users with collection-only access grant can view without brand membership.
     *
     * Rules:
     * - C12: If user has accepted collection_user grant for this collection → allow (no brand required)
     * - If user cannot view the brand → deny
     * - If visibility = brand → allow (anyone who can view the brand)
     * - If visibility = restricted → allow if user is creator or in collection_members
     * - If visibility = private → allow if user is creator or in collection_members
     * - Otherwise deny
     *
     * No Spatie checks. No asset permission elevation.
     */
    public function view(User $user, Collection $collection): bool
    {
        // C12: Collection-only access grant (no brand membership)
        if ($user->collectionAccessGrants()->where('collection_id', $collection->id)->whereNotNull('accepted_at')->exists()) {
            return true;
        }

        if ($user->activeBrandMembership($collection->brand) === null) {
            return false;
        }

        $visibility = $collection->visibility ?? 'brand';

        if ($visibility === 'brand') {
            return true;
        }

        if ($visibility === 'restricted' || $visibility === 'private') {
            if ($collection->created_by !== null && (int) $collection->created_by === (int) $user->id) {
                return true;
            }
            // C7: Only accepted members can view (invited but not accepted = no access)
            if ($collection->relationLoaded('members')) {
                return $collection->members
                    ->where('user_id', $user->id)
                    ->whereNotNull('accepted_at')
                    ->isNotEmpty();
            }

            return $collection->members()
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at')
                ->exists();
        }

        return false;
    }

    /**
     * Determine if the user can create collections for the brand.
     * C9: Brand admin, brand manager, and contributor may create; viewer may not.
     * Tenant owner/admin can create (permission cascade - matches AssetPolicy, BrandPolicy, CategoryPolicy).
     */
    public function create(User $user, Brand $brand): bool
    {
        if (! $user->tenants()->where('tenants.id', $brand->tenant_id)->exists()) {
            return false;
        }

        $tenant = $brand->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        // Tenant owners/admins can create collections for any brand (permission cascade)
        if ($isTenantOwnerOrAdmin && $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return true;
        }

        $membership = $user->activeBrandMembership($brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        if ($role === null) {
            return false;
        }
        return in_array($role, ['admin', 'brand_manager', 'contributor'], true);
    }

    /**
     * Determine if the user can update the collection.
     * Only brand admins and brand managers may update.
     * Tenant owner/admin can update (permission cascade - matches create, addAsset).
     */
    public function update(User $user, Collection $collection): bool
    {
        if (! $user->tenants()->where('tenants.id', $collection->tenant_id)->exists()) {
            return false;
        }

        $tenant = $collection->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        if ($isTenantOwnerOrAdmin && $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return true;
        }

        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        return $role !== null && RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Determine if the user can delete the collection.
     * Only brand admins and brand managers may delete.
     */
    public function delete(User $user, Collection $collection): bool
    {
        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        return $role !== null && RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Determine if the user can add/remove assets in the collection.
     * Only brand admins and brand managers may manage assets.
     */
    public function manageAssets(User $user, Collection $collection): bool
    {
        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        return $role !== null && RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Determine if the user can add assets to the collection (C5).
     * Brand admin, brand manager, and contributor may add; viewer may not.
     * Tenant owner/admin can add (permission cascade - matches create).
     */
    public function addAsset(User $user, Collection $collection): bool
    {
        if (! $user->tenants()->where('tenants.id', $collection->tenant_id)->exists()) {
            return false;
        }

        $tenant = $collection->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        if ($isTenantOwnerOrAdmin && $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return true;
        }

        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        if ($role === null) {
            return false;
        }
        return in_array($role, ['admin', 'brand_manager', 'contributor'], true);
    }

    /**
     * Determine if the user can remove assets from the collection (C5).
     * Only brand admin and brand manager may remove; contributor and viewer may not.
     * Tenant owner/admin can remove (permission cascade - matches create).
     */
    public function removeAsset(User $user, Collection $collection): bool
    {
        if (! $user->tenants()->where('tenants.id', $collection->tenant_id)->exists()) {
            return false;
        }

        $tenant = $collection->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        if ($isTenantOwnerOrAdmin && $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return true;
        }

        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        return $role !== null && RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Determine if the user can invite members to the collection (C7).
     * Brand admin, brand manager, or creator may invite.
     */
    public function invite(User $user, Collection $collection): bool
    {
        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        if ($role !== null && RoleRegistry::isBrandApproverRole($role)) {
            return true;
        }
        return $collection->created_by !== null && (int) $collection->created_by === (int) $user->id;
    }

    /**
     * Determine if the user can remove a member from the collection (C7).
     * Brand admin, brand manager, or creator may remove members.
     */
    public function removeMember(User $user, Collection $collection): bool
    {
        $membership = $user->activeBrandMembership($collection->brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        if ($role !== null && RoleRegistry::isBrandApproverRole($role)) {
            return true;
        }
        return $collection->created_by !== null && (int) $collection->created_by === (int) $user->id;
    }
}
