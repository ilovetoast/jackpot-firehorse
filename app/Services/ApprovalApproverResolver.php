<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves users who may approve assets for a brand (tenant-scoped, brand-assigned or tenant admin).
 */
final class ApprovalApproverResolver
{
    /**
     * @return Collection<int, User>
     */
    public function approversForBrand(Brand $brand, ?User $excludeUploader = null): Collection
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return collect();
        }

        $tenantUsers = User::query()
            ->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id))
            ->get();

        $approvers = $tenantUsers->filter(function (User $user) use ($tenant, $brand) {
            if (! $user->hasPermissionForTenant($tenant, 'asset.publish')) {
                return false;
            }

            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantAdmin = in_array($tenantRole, ['admin', 'owner'], true);
            $isBrandAssigned = $user->isAssignedToBrandId($brand->id);

            return $isTenantAdmin || $isBrandAssigned;
        });

        if ($excludeUploader) {
            $approvers = $approvers->reject(fn (User $u) => $u->id === $excludeUploader->id);
        }

        return $approvers->values();
    }
}
