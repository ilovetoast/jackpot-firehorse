<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\PermissionMap;

/**
 * Tenant Permission Resolver
 * 
 * SINGLE SOURCE OF TRUTH for tenant-scoped permission checks.
 * 
 * This service ensures that ALL tenant permission checks follow the same logic:
 * 1. Check PermissionMap FIRST (owner/admin have all permissions)
 * 2. Check Spatie roles ONLY if PermissionMap doesn't grant access
 * 
 * Rules:
 * - PermissionMap is authoritative for owner/admin roles
 * - Spatie permissions are additive for fine-grained control
 * - No controller or service should bypass this resolver
 * - This prevents the bug where owner/admin permissions were missed
 * 
 * Usage:
 * ```php
 * $resolver = app(TenantPermissionResolver::class);
 * $canPublish = $resolver->has($user, $tenant, 'asset.publish');
 * ```
 */
class TenantPermissionResolver
{
    /**
     * Check if a user has a permission for a tenant.
     * 
     * This is the CANONICAL method for all tenant permission checks.
     * 
     * @param User|null $user The user to check
     * @param Tenant $tenant The tenant context
     * @param string $permission The permission to check (e.g., 'asset.publish', 'metadata.bypass_approval')
     * @return bool True if user has the permission
     */
    public function has(?User $user, Tenant $tenant, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        // Step 1: Check PermissionMap FIRST (authoritative for owner/admin)
        $tenantRole = $user->getRoleForTenant($tenant);
        if ($tenantRole) {
            $permissionMap = PermissionMap::tenantPermissions();
            $rolePermissions = $permissionMap[strtolower($tenantRole)] ?? [];
            
            // Owner/Admin have all permissions via PermissionMap
            if (in_array($permission, $rolePermissions)) {
                return true;
            }
        }

        // Step 2: Check Spatie roles (for fine-grained permissions)
        // This handles cases where permissions are assigned via Spatie roles
        // but not yet in PermissionMap, or for custom role configurations
        return $user->hasPermissionForTenant($tenant, $permission);
    }

    /**
     * Debug check: returns result and source for admin troubleshooting.
     *
     * @return array{result: bool, source: string} source: 'PermissionMap', 'Spatie', or 'denied'
     */
    public function debugCheck(?User $user, ?Tenant $tenant, ?Brand $brand, string $permission): array
    {
        if (!$user) {
            return ['result' => false, 'source' => 'denied'];
        }

        if ($brand) {
            $tenant = $brand->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);
            if ($tenantRole) {
                $rolePermissions = PermissionMap::tenantPermissions()[strtolower($tenantRole)] ?? [];
                if (in_array($permission, $rolePermissions)) {
                    return ['result' => true, 'source' => 'PermissionMap'];
                }
            }
            $brandRole = $user->getRoleForBrand($brand);
            if ($brandRole) {
                $brandPerms = PermissionMap::brandPermissions()[strtolower($brandRole)] ?? [];
                if (in_array($permission, $brandPerms)) {
                    return ['result' => true, 'source' => 'PermissionMap'];
                }
            }
            if ($user->hasPermissionForBrand($brand, $permission)) {
                return ['result' => true, 'source' => 'Spatie'];
            }
            return ['result' => false, 'source' => 'denied'];
        }

        if ($tenant) {
            $tenantRole = $user->getRoleForTenant($tenant);
            if ($tenantRole) {
                $rolePermissions = PermissionMap::tenantPermissions()[strtolower($tenantRole)] ?? [];
                if (in_array($permission, $rolePermissions)) {
                    return ['result' => true, 'source' => 'PermissionMap'];
                }
            }
            if ($user->hasPermissionForTenant($tenant, $permission)) {
                return ['result' => true, 'source' => 'Spatie'];
            }
            return ['result' => false, 'source' => 'denied'];
        }

        // Site-level (no tenant)
        if ($user->can($permission)) {
            return ['result' => true, 'source' => 'Spatie'];
        }
        return ['result' => false, 'source' => 'denied'];
    }

    /**
     * Check if a user has a permission for a brand.
     * 
     * Brand permissions are checked after tenant permissions.
     * 
     * @param User|null $user The user to check
     * @param Brand $brand The brand context
     * @param string $permission The permission to check
     * @return bool True if user has the permission
     */
    public function hasForBrand(?User $user, Brand $brand, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        // Check tenant-level permission first (owner/admin have all permissions)
        $tenant = $brand->tenant;
        if ($this->has($user, $tenant, $permission)) {
            return true;
        }

        // Check brand-level permission: PermissionMap FIRST, then Spatie
        $brandRole = $user->getRoleForBrand($brand);
        if ($brandRole) {
            $brandPermissions = PermissionMap::brandPermissions()[strtolower($brandRole)] ?? [];
            if (in_array($permission, $brandPermissions)) {
                return true;
            }
        }

        return $user->hasPermissionForBrand($brand, $permission);
    }
}
