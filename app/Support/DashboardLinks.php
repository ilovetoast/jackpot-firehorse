<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;

/**
 * Contextual “Dashboards” header links (Company / Brand). Agency dashboard access is in the top app bar.
 */
final class DashboardLinks
{
    /**
     * Labels for workspace menu links (company / tenant vs brand). Kept in sync with AppNav + GlobalUserControls.
     * When company and brand names match, same labels: company = “Company settings”, brand = “{Name} Settings”.
     *
     * @return array{company: string, brand: string}
     */
    public static function workspaceSettingsLabels(?string $companyName, ?string $brandName): array
    {
        $b = $brandName !== null ? trim($brandName) : '';

        return [
            'company' => 'Company settings',
            'brand' => $b !== '' ? $b.' Settings' : 'Brand settings',
        ];
    }

    /**
     * Short labels for the “Dashboards” header row (no “settings” suffix).
     *
     * @return array{company: string, brand: string}
     */
    public static function workspaceDashboardShortLabels(?string $companyName, ?string $brandName): array
    {
        $c = $companyName !== null ? trim($companyName) : '';
        $b = $brandName !== null ? trim($brandName) : '';
        $same = $c !== '' && $b !== '' && strcasecmp($c, $b) === 0;

        if ($same) {
            return ['company' => 'Company', 'brand' => 'Brand'];
        }

        return [
            'company' => $c !== '' ? $c : 'Company',
            'brand' => $b !== '' ? $b : 'Brand',
        ];
    }

    /**
     * Company settings page (administrative), not company overview.
     */
    public static function companySettingsHref(User $user, Tenant $tenant): ?string
    {
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            return null;
        }

        return '/app/companies/settings';
    }

    /**
     * Brand Settings / Brand Portal editor (Identity tab by default).
     */
    public static function brandEditHref(User $user, Tenant $tenant, ?Brand $brand): ?string
    {
        if (! $brand) {
            return null;
        }

        if (! $user->hasPermissionForBrand($brand, 'brand_settings.manage')) {
            return null;
        }

        return '/app/brands/'.$brand->id.'/edit';
    }

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
     * Cinematic brand overview (/app/overview) for the active brand workspace.
     */
    public static function brandOverviewHref(User $user, Tenant $tenant, ?Brand $brand): ?string
    {
        if (! $brand) {
            return null;
        }

        if (! $user->hasPermissionForBrand($brand, 'asset.view')) {
            return null;
        }

        return '/app/overview';
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
