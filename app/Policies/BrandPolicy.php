<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
        \Log::info('BrandPolicy::view() check', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'brand_id' => $brand->id,
            'brand_name' => $brand->name,
            'tenant_id' => $brand->tenant_id,
        ]);

        // User must belong to the tenant
        $belongsToTenant = $user->tenants()->where('tenants.id', $brand->tenant_id)->exists();
        \Log::info('BrandPolicy::view() - tenant check', [
            'user_id' => $user->id,
            'belongs_to_tenant' => $belongsToTenant,
        ]);
        
        if (!$belongsToTenant) {
            \Log::warning('BrandPolicy::view() - User does not belong to tenant', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'tenant_id' => $brand->tenant_id,
            ]);
            return false;
        }

        // Get user's tenant role
        $tenantRole = $user->getRoleForTenant($brand->tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        
        \Log::info('BrandPolicy::view() - role check', [
            'user_id' => $user->id,
            'tenant_role' => $tenantRole,
            'is_owner_or_admin' => $isTenantOwnerOrAdmin,
        ]);

        // For tenant owners/admins, they can view all brands in their tenant
        if ($isTenantOwnerOrAdmin) {
            $hasPermission = $user->hasPermissionForTenant($brand->tenant, 'view brand');
            \Log::info('BrandPolicy::view() - owner/admin permission check', [
                'user_id' => $user->id,
                'has_permission' => $hasPermission,
            ]);
            return $hasPermission;
        }

        // Phase MI-1: For regular users, they MUST have active brand membership
        // Use activeBrandMembership to verify active status (removed_at IS NULL)
        $membership = $user->activeBrandMembership($brand);
        
        \Log::info('BrandPolicy::view() - active membership check', [
            'user_id' => $user->id,
            'brand_id' => $brand->id,
            'has_active_membership' => $membership !== null,
            'membership_role' => $membership['role'] ?? null,
        ]);
        
        if (!$membership) {
            \Log::warning('BrandPolicy::view() - User does not have active brand membership', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
            ]);
            return false;
        }

        // User is assigned to brand, check if they have permission
        $hasPermission = $user->hasPermissionForBrand($brand, 'view brand');
        \Log::info('BrandPolicy::view() - final permission check', [
            'user_id' => $user->id,
            'has_permission' => $hasPermission,
        ]);
        
        return $hasPermission;
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
        // User must belong to the tenant
        if (!$user->tenants()->where('tenants.id', $brand->tenant_id)->exists()) {
            return false;
        }

        // Get user's tenant role
        $tenantRole = $user->getRoleForTenant($brand->tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        // For tenant owners/admins, they can manage all brands in their tenant
        if ($isTenantOwnerOrAdmin) {
            return $user->hasPermissionForTenant($brand->tenant, 'brand_settings.manage');
        }

        // Phase MI-1: For regular users, they MUST have active brand membership
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return false;
        }

        // User is assigned to brand, check if they have permission
        return $user->hasPermissionForBrand($brand, 'brand_settings.manage');
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
