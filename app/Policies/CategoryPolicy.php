<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

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
     */
    public function view(User $user, Category $category): bool
    {
        // User must belong to the tenant
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        // If category is private, user needs view private category permission
        if ($category->is_private && ! $user->can('view private category')) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can create categories.
     */
    public function create(User $user): bool
    {
        return $user->can('manage categories');
    }

    /**
     * Determine if the user can update the category.
     */
    public function update(User $user, Category $category): bool
    {
        // User must belong to the tenant and have manage categories permission
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        if (! $user->can('manage categories')) {
            return false;
        }

        // Cannot update locked/system categories
        if ($category->is_locked || $category->is_system) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can delete the category.
     */
    public function delete(User $user, Category $category): bool
    {
        // User must belong to the tenant and have manage categories permission
        if (! $user->tenants()->where('tenants.id', $category->tenant_id)->exists()) {
            return false;
        }

        if (! $user->can('manage categories')) {
            return false;
        }

        // Cannot delete locked/system categories
        if ($category->is_locked || $category->is_system) {
            return false;
        }

        return true;
    }
}
