<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;

/**
 * Contextual “Dashboards” header links (Company / Agency / Brand).
 * Company and Brand stay in the current tenant/brand workspace; only Agency may switch to the agency’s own tenant.
 */
final class DashboardLinks
{
    public static function companyOverviewHref(User $user, Tenant $tenant): ?string
    {
        if (! $user->hasPermissionForTenant($tenant, 'company.view')) {
            return null;
        }

        return '/app';
    }

    /**
     * Brand Portal (settings) for the brand in the current workspace — same tenant/brand context, no company switch.
     */
    public static function brandPortalHref(User $user, Tenant $tenant, ?Brand $brand): ?string
    {
        if (! $brand) {
            return null;
        }

        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return null;
        }

        return '/app/brands/'.$brand->id.'/edit#brand-portal';
    }

    /**
     * Company Portal: link to Agency dashboard when the user belongs to an agency — either already on that agency tenant,
     * or switching from a linked client workspace.
     *
     * @return array{href: string, switch_tenant_id: int|null}|null
     */
    public static function agencyDashboardLinkForCompanyPortal(User $user, Tenant $currentTenant): ?array
    {
        $agencyTenantIds = $user->tenants()->where('tenants.is_agency', true)->pluck('tenants.id');
        if ($agencyTenantIds->isEmpty()) {
            return null;
        }

        if ($currentTenant->is_agency) {
            if (! $agencyTenantIds->contains($currentTenant->id)) {
                return null;
            }

            return [
                'href' => '/app/agency/dashboard',
                'switch_tenant_id' => null,
            ];
        }

        $linkedAgencyIds = TenantAgency::query()
            ->where('tenant_id', $currentTenant->id)
            ->pluck('agency_tenant_id');

        $targetAgencyId = $linkedAgencyIds->first(fn ($id) => $agencyTenantIds->contains((int) $id));
        if ($targetAgencyId === null) {
            return null;
        }

        return [
            'href' => '/app/agency/dashboard',
            'switch_tenant_id' => (int) $targetAgencyId,
        ];
    }
}
