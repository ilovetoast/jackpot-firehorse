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
 * - Private categories (is_private=true): Require 'view private category' permission
 * - Hidden categories (is_hidden=true): Require 'manage categories' permission (or specific permission)
 *
 * Mutation Rules:
 * - System categories (is_system=true): Cannot be updated or deleted
 * - Locked categories (is_locked=true): Cannot be updated or deleted
 * - Custom categories: Can be updated/deleted by users with 'manage categories' permission
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
     * - Private flag (requires 'view private category' permission)
     * - Hidden flag (requires 'manage categories' permission)
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

        // If category is hidden, user needs manage categories permission
        // This allows admins to see hidden categories while regular users cannot
        if ($category->is_hidden && ! $user->can('manage categories')) {
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
     *
     * System and locked categories cannot be deleted, even by admins.
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

        // Cannot delete locked/system categories (enforced at both policy and service level)
        if ($category->is_locked || $category->is_system) {
            return false;
        }

        return true;
    }
}
