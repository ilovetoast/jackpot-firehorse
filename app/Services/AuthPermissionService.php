<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\PermissionMap;
use Spatie\Permission\Models\Role;

class AuthPermissionService
{
    /**
     * Get all effective permissions for a user in the given tenant/brand context.
     * Merges tenant role, Spatie (site) roles, and brand role permissions.
     * No prefixes, no role keys, no collisions — just merged permission strings.
     * When tenant is null, returns only Spatie (site) role permissions.
     */
    public function effectivePermissions(User $user, ?Tenant $tenant = null, ?Brand $brand = null): array
    {
        $permissions = [];

        // Tenant role permissions (from tenant_user pivot; lookup via Spatie Role)
        if ($tenant) {
            $tenantRole = $user->getRoleForTenant($tenant);
            if ($tenantRole) {
                $roleModel = Role::where('name', $tenantRole)->first();
                if ($roleModel) {
                    $permissions = array_merge(
                        $permissions,
                        $roleModel->permissions->pluck('name')->toArray()
                    );
                }
            }
        }

        // Spatie (site) role permissions (site_admin, site_owner, etc.)
        foreach ($user->roles as $role) {
            $permissions = array_merge(
                $permissions,
                $role->permissions->pluck('name')->toArray()
            );
        }

        // Brand role permissions (from brand_user pivot; lookup via PermissionMap)
        // CRITICAL: Verify brand belongs to tenant to prevent cross-tenant permission leakage
        if ($brand && $tenant && $brand->tenant_id === $tenant->id) {
            $brandRole = $user->getRoleForBrand($brand);
            if ($brandRole) {
                $brandPermissions = PermissionMap::brandPermissions()[$brandRole] ?? [];
                $permissions = array_merge($permissions, $brandPermissions);
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Check if a user has a permission in the given tenant/brand context.
     * Single backend entry point for controllers, policies, jobs, and API endpoints.
     *
     * @param User $user
     * @param string $permission
     * @param Tenant|null $tenant
     * @param Brand|null $brand
     * @return bool
     */
    public function can(User $user, string $permission, ?Tenant $tenant = null, ?Brand $brand = null): bool
    {
        $permissions = $this->effectivePermissions($user, $tenant, $brand);

        return in_array($permission, $permissions);
    }
}
