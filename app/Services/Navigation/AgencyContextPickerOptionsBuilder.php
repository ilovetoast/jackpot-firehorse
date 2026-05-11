<?php

namespace App\Services\Navigation;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgencyBrandAccessService;

/**
 * Authoritative payload for the unified agency context picker in the main nav (AppBrandLogo).
 * Normal tenants never receive this structure — see {@see self::build()}.
 */
class AgencyContextPickerOptionsBuilder
{
    public function __construct(
        protected AgencyBrandAccessService $agencyBrandAccessService
    ) {}

    /**
     * @param  array<int, string>  $effectivePermissions
     * @param  array<string, mixed>|null  $planLimitInfo
     * @return array<string, mixed>|null
     */
    public function build(
        User $user,
        Tenant $sessionTenant,
        Brand $activeBrand,
        array $effectivePermissions,
        ?array $planLimitInfo
    ): ?array {
        $pivot = $user->tenants->firstWhere('id', $sessionTenant->id)?->pivot;
        $activeIsManaged = (bool) ($pivot->is_agency_managed ?? false);
        if (! $sessionTenant->is_agency && ! $activeIsManaged) {
            return null;
        }

        $grouped = $this->agencyBrandAccessService->groupedAgencyPortfolioBrands($user);
        if ($grouped === null) {
            return null;
        }

        $groups = [];
        $totalItems = 0;

        if ($grouped['agency_brands'] !== []) {
            $items = [];
            foreach ($grouped['agency_brands'] as $b) {
                $items[] = $this->mapBrandItem(
                    $b,
                    (int) $grouped['agency_tenant_id'],
                    (string) $grouped['agency_tenant_name'],
                    'agency_owned',
                    $sessionTenant->id,
                    $activeBrand->id
                );
            }
            $totalItems += count($items);
            $groups[] = [
                'type' => 'agency',
                'section_label' => 'AGENCY WORKSPACE',
                'tenant_id' => (int) $grouped['agency_tenant_id'],
                'tenant_name' => (string) $grouped['agency_tenant_name'],
                'items' => $items,
            ];
        }

        $managedSectionPlaced = false;
        foreach ($grouped['client_workspaces'] as $workspace) {
            $items = [];
            foreach ($workspace['brands'] as $b) {
                $items[] = $this->mapBrandItem(
                    $b,
                    (int) $workspace['tenant_id'],
                    (string) $workspace['tenant_name'],
                    'managed_client',
                    $sessionTenant->id,
                    $activeBrand->id
                );
            }
            if ($items === []) {
                continue;
            }
            $totalItems += count($items);
            $groups[] = [
                'type' => 'client',
                'section_label' => $managedSectionPlaced ? null : 'MANAGED CLIENTS',
                'tenant_id' => (int) $workspace['tenant_id'],
                'tenant_name' => (string) $workspace['tenant_name'],
                'items' => $items,
            ];
            $managedSectionPlaced = true;
        }

        if ($groups === [] || $totalItems === 0) {
            return null;
        }

        $canAddBrand = in_array('brand_settings.manage', $effectivePermissions, true)
            && is_array($planLimitInfo)
            && empty($planLimitInfo['brand_limit_exceeded']);

        return [
            'is_agency_context_picker' => true,
            'has_multiple_contexts' => $totalItems > 1,
            'active_tenant_id' => (int) $sessionTenant->id,
            'active_tenant_name' => (string) $sessionTenant->name,
            'active_brand_id' => (int) $activeBrand->id,
            'active_brand_name' => (string) $activeBrand->name,
            'agency_tenant_id' => (int) $grouped['agency_tenant_id'],
            'agency_tenant_name' => (string) $grouped['agency_tenant_name'],
            'groups' => $groups,
            'can_add_brand_for_active_tenant' => $canAddBrand,
            'add_brand_url' => '/app/brands/create',
        ];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    protected function mapBrandItem(
        array $b,
        int $tenantId,
        string $tenantName,
        string $relationship,
        int $sessionTenantId,
        int $sessionBrandId
    ): array {
        $brandId = (int) ($b['id'] ?? 0);
        $brandName = (string) ($b['name'] ?? '');

        return [
            'type' => 'brand',
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'brand_id' => $brandId,
            'brand_name' => $brandName,
            'initials' => $this->initialsFromBrandName($brandName),
            'relationship' => $relationship,
            'is_active' => $sessionTenantId === $tenantId && $sessionBrandId === $brandId,
            'logo_url' => $b['logo_url'] ?? null,
            'logo_dark_url' => $b['logo_dark_url'] ?? null,
            'primary_color' => $b['primary_color'] ?? null,
            'is_default' => (bool) ($b['is_default'] ?? false),
        ];
    }

    protected function initialsFromBrandName(string $name): string
    {
        $t = trim($name);
        if ($t === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $t) ?: [];
        if (count($parts) >= 2) {
            $a = (string) ($parts[0][0] ?? '');
            $last = $parts[count($parts) - 1];
            $b = (string) ($last[0] ?? '');

            return strtoupper($a.$b);
        }

        return strtoupper(substr($t, 0, min(2, strlen($t))));
    }
}
