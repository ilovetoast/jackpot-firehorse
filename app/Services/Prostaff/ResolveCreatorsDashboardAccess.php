<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureGate;

/**
 * Who may open the Creators **management** dashboard (roster, invites, full JSON) vs. individual creator UX.
 *
 * {@see self::canView()} — brand-level Creators list + manager dashboard API (not individual creator profiles).
 * Brand viewers and uploaders (contributors) use {@see ProstaffDashboardController::creatorPage},
 * {@see ProstaffDashboardController::creatorSelfProgress}, and GET /api/prostaff/me when enrolled as prostaff.
 */
final class ResolveCreatorsDashboardAccess
{
    public function __construct(
        private FeatureGate $featureGate
    ) {}

    /**
     * Creators list page and GET .../prostaff/dashboard JSON — brand admin / brand_manager only.
     */
    public function canView(User $user, Tenant $tenant, Brand $brand): bool
    {
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

        $brandRole = strtolower((string) ($membership['role'] ?? ''));

        // Never the management module for read-only or uploader brand roles — even if tenant role is elevated.
        if (in_array($brandRole, ['viewer', 'contributor'], true)) {
            return false;
        }

        return in_array($brandRole, ['admin', 'brand_manager'], true);
    }
}
