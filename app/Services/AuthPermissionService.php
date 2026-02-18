<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\PermissionMap;
use Spatie\Permission\Models\Role;

/**
 * Auth Permission Service
 *
 * Delegates to TenantPermissionResolver for tenant/brand permission checks.
 * effectivePermissions() iterates PermissionMap::allPermissions() and calls can() for each.
 * This guarantees backend === frontend, no drift.
 */
class AuthPermissionService
{
    public function __construct(
        protected TenantPermissionResolver $tenantResolver
    ) {}

    /**
     * Check if a user has a permission in the given tenant/brand context.
     * Delegates to TenantPermissionResolver for tenant/brand; Spatie for site-level.
     */
    public function can(User $user, string $permission, ?Tenant $tenant = null, ?Brand $brand = null): bool
    {
        if ($tenant) {
            if ($brand && $brand->tenant_id === $tenant->id) {
                return $this->tenantResolver->hasForBrand($user, $brand, $permission);
            }

            return $this->tenantResolver->has($user, $tenant, $permission);
        }

        return $user->can($permission);
    }

    /**
     * Get all effective permissions for a user in the given tenant/brand context.
     * Iterates PermissionMap::allPermissions() and includes each if can() returns true.
     * Also includes custom Spatie permissions not in PermissionMap.
     */
    public function effectivePermissions(User $user, ?Tenant $tenant = null, ?Brand $brand = null): array
    {
        $permissions = [];

        foreach (PermissionMap::allPermissions() as $permission) {
            if ($this->can($user, $permission, $tenant, $brand)) {
                $permissions[] = $permission;
            }
        }

        // Include custom Spatie permissions not in PermissionMap
        foreach ($user->roles as $role) {
            foreach ($role->permissions->pluck('name')->toArray() as $perm) {
                if (!in_array($perm, $permissions, true)) {
                    $permissions[] = $perm;
                }
            }
        }

        if ($user->id === 1) {
            $siteOwnerRole = Role::where('name', 'site_owner')->where('guard_name', 'web')->first();
            if ($siteOwnerRole) {
                foreach ($siteOwnerRole->permissions->pluck('name')->toArray() as $perm) {
                    if (!in_array($perm, $permissions, true)) {
                        $permissions[] = $perm;
                    }
                }
            }
        }

        return array_values(array_unique($permissions));
    }
}
