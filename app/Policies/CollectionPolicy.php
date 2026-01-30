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
     *
     * Rules:
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
     * Only brand admins and brand managers may create.
     */
    public function create(User $user, Brand $brand): bool
    {
        $membership = $user->activeBrandMembership($brand);
        if ($membership === null) {
            return false;
        }
        $role = $membership['role'] ?? null;
        return $role !== null && RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Determine if the user can update the collection.
     * Only brand admins and brand managers may update.
     */
    public function update(User $user, Collection $collection): bool
    {
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
     */
    public function addAsset(User $user, Collection $collection): bool
    {
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
     */
    public function removeAsset(User $user, Collection $collection): bool
    {
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
