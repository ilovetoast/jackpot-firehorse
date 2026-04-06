<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureGate;

/**
 * Who may open the Creators dashboard vs. manage prostaff assignments.
 */
final class ResolveCreatorsDashboardAccess
{
    public function __construct(
        private FeatureGate $featureGate
    ) {}

    public function canView(User $user, Tenant $tenant, Brand $brand): bool
    {
        if ($brand->tenant_id !== $tenant->id) {
            return false;
        }

        if (! $this->featureGate->creatorModuleEnabled($tenant)) {
            return false;
        }

        if ($user->isProstaffForBrand($brand)) {
            return true;
        }

        return $this->canManage($user, $tenant, $brand);
    }

    public function canManage(User $user, Tenant $tenant, Brand $brand): bool
    {
        if ($brand->tenant_id !== $tenant->id) {
            return false;
        }

        if (! $this->featureGate->creatorModuleEnabled($tenant)) {
            return false;
        }

        $membership = $user->activeBrandMembership($brand);
        if ($membership === null) {
            return false;
        }

        $brandRole = $membership['role'];
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin'], true);
        $isBrandManager = $brandRole === 'brand_manager';
        $isContributor = $brandRole === 'contributor';

        if ($isContributor && ! $isTenantOwnerOrAdmin && ! $isBrandManager) {
            return false;
        }

        return true;
    }
}
