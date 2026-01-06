<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    /**
     * Determine if the user can view any brands.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view brand');
    }

    /**
     * Determine if the user can view the brand.
     */
    public function view(User $user, Brand $brand): bool
    {
        // User must belong to the tenant and have view brand permission
        return $user->tenants()->where('tenants.id', $brand->tenant_id)->exists()
            && $user->can('view brand');
    }

    /**
     * Determine if the user can create brands.
     */
    public function create(User $user): bool
    {
        return $user->can('manage brands');
    }

    /**
     * Determine if the user can update the brand.
     */
    public function update(User $user, Brand $brand): bool
    {
        $tenant = $brand->tenant;
        return $user->tenants()->where('tenants.id', $brand->tenant_id)->exists()
            && $user->hasPermissionForTenant($tenant, 'brand_settings.manage');
    }

    /**
     * Determine if the user can delete the brand.
     */
    public function delete(User $user, Brand $brand): bool
    {
        return $user->tenants()->where('tenants.id', $brand->tenant_id)->exists()
            && $user->can('manage brands');
    }
}
