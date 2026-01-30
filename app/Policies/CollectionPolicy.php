<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\User;
use App\Support\Roles\RoleRegistry;

class CollectionPolicy
{
    /**
     * Determine if the user can view the collection.
     * User must belong to the collection's brand (active brand membership).
     */
    public function view(User $user, Collection $collection): bool
    {
        return $user->activeBrandMembership($collection->brand) !== null;
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
}
