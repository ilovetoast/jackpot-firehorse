<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Standardized Company Data Service
 * 
 * Ensures admin and tenant views always use the same methods for company data.
 * This prevents discrepancies between admin and tenant views.
 */
class CompanyDataService
{
    /**
     * Get company users using standardized query method.
     * This ensures we avoid orphan issues by properly querying through the tenant relationship.
     * 
     * Used by both Admin/CompanyViewController and SiteAdminController to ensure consistency.
     * 
     * @param Tenant $tenant
     * @param int|null $limit Limit number of results (null for all)
     * @return \Illuminate\Support\Collection
     */
    public function getCompanyUsers(Tenant $tenant, ?int $limit = null)
    {
        $query = $tenant->users()
            ->orderBy('tenant_user.created_at', 'desc');
        
        if ($limit) {
            $query->limit($limit);
        }
        
        return $query->get()->map(function ($user) use ($tenant) {
            return [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'role' => $user->getRoleForTenant($tenant),
                'is_owner' => $tenant->isOwner($user),
            ];
        })->values(); // Reset keys for proper array serialization
    }

    /**
     * Ensure owner is connected to default brand.
     * This is a data integrity enforcement method that ensures owners are always connected to their default brand.
     * 
     * @param Tenant $tenant
     * @return void
     */
    public function ensureOwnerConnectedToDefaultBrand(Tenant $tenant): void
    {
        $owner = $tenant->owner();
        $defaultBrand = $tenant->defaultBrand;
        
        if (!$owner || !$defaultBrand) {
            return; // Can't enforce if owner or default brand doesn't exist
        }
        
        // Check if owner is already connected to default brand
        $isConnected = $defaultBrand->users()->where('users.id', $owner->id)->exists();
        
        if (!$isConnected) {
            // Connect owner to default brand with admin role (owners can't have brand roles)
            $defaultBrand->users()->syncWithoutDetaching([
                $owner->id => ['role' => 'admin']
            ]);
        }
    }
}
