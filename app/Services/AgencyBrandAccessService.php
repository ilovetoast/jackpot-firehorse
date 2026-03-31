<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;

class AgencyBrandAccessService
{
    /**
     * First tenant in the user's memberships that is an agency (matches agency strip / nav).
     */
    public function agencyTenantForUser(User $user): ?Tenant
    {
        return $user->tenants->first(fn (Tenant $t) => $t->is_agency);
    }

    /**
     * Linked client companies (tenant_agencies) for the user's agency — not the active session tenant.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function managedAgencyClientSummariesForUser(User $user): array
    {
        $agency = $this->agencyTenantForUser($user);
        if (! $agency) {
            return [];
        }

        $linkedClientIds = TenantAgency::query()
            ->where('agency_tenant_id', $agency->id)
            ->pluck('tenant_id');
        if ($linkedClientIds->isEmpty()) {
            return [];
        }

        $memberIds = $user->tenants()->pluck('tenants.id');
        $accessibleIds = $linkedClientIds->intersect($memberIds);
        if ($accessibleIds->isEmpty()) {
            return [];
        }

        return Tenant::query()
            ->whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, brands: array<int, array{id: int, name: string, is_default: bool, logo_url: ?string, logo_dark_url: ?string, primary_color: ?string}>}>
     */
    public function managedAgencyClientsForUser(User $user, Tenant $agencyTenant): array
    {
        if (! $agencyTenant->is_agency) {
            return [];
        }

        $links = TenantAgency::query()
            ->where('agency_tenant_id', $agencyTenant->id)
            ->get(['id', 'tenant_id']);
        if ($links->isEmpty()) {
            return [];
        }

        $tenantAgencyIdByClientId = $links->pluck('id', 'tenant_id');
        $linkedClientIds = $links->pluck('tenant_id');

        $memberIds = $user->tenants()->pluck('tenants.id');
        $accessibleIds = $linkedClientIds->intersect($memberIds);
        if ($accessibleIds->isEmpty()) {
            return [];
        }

        return Tenant::query()
            ->whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(function (Tenant $t) use ($user, $tenantAgencyIdByClientId) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'tenant_agency_id' => $tenantAgencyIdByClientId[$t->id] ?? null,
                    'brands' => $this->brandsUserCanOpenInClientTenant($user, $t),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Brands the user can open in the agency workspace strip: agency tenant brands plus linked client brands.
     *
     * @return array<int, array{
     *     tenant_id: int,
     *     tenant_slug: string,
     *     id: int,
     *     name: string,
     *     is_default: bool,
     *     logo_url: ?string,
     *     logo_dark_url: ?string,
     *     primary_color: ?string
     * }>
     */
    public function flatBrandsForAgencyStrip(User $user): array
    {
        $user->loadMissing('tenants');
        $user->warmTenantRoleCacheFromLoadedTenants();

        $agency = $this->agencyTenantForUser($user);
        if (! $agency) {
            return [];
        }

        $flat = [];

        foreach ($this->brandsUserCanOpenInClientTenant($user, $agency) as $b) {
            $flat[] = array_merge($b, [
                'tenant_id' => $agency->id,
                'tenant_slug' => $agency->slug,
            ]);
        }

        foreach ($this->managedAgencyClientsForUser($user, $agency) as $client) {
            foreach ($client['brands'] as $b) {
                $flat[] = array_merge($b, [
                    'tenant_id' => $client['id'],
                    'tenant_slug' => $client['slug'],
                ]);
            }
        }

        usort($flat, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_values($flat);
    }

    /**
     * Brands the user may open when switching into a tenant (tenant admins see all brands).
     *
     * @return array<int, array{id: int, name: string, is_default: bool, logo_url: ?string, logo_dark_url: ?string, primary_color: ?string}>
     */
    protected function brandsUserCanOpenInClientTenant(User $user, Tenant $clientTenant): array
    {
        $role = $user->getRoleForTenant($clientTenant);
        $tenantWide = in_array($role, ['admin', 'owner', 'agency_admin'], true);

        $mapBrand = function (Brand $b): array {
            return [
                'id' => $b->id,
                'name' => $b->name,
                'is_default' => (bool) $b->is_default,
                'logo_url' => $b->logoUrlForGuest(false),
                'logo_dark_url' => $b->logoUrlForGuest(true),
                'primary_color' => $b->primary_color,
            ];
        };

        if ($tenantWide) {
            return Brand::query()
                ->where('tenant_id', $clientTenant->id)
                ->orderBy('name')
                ->get(['id', 'name', 'is_default', 'logo_id', 'logo_dark_id', 'logo_path', 'logo_dark_path', 'primary_color'])
                ->map($mapBrand)
                ->values()
                ->all();
        }

        return $user->brands()
            ->where('brands.tenant_id', $clientTenant->id)
            ->wherePivotNull('removed_at')
            ->orderBy('brands.name')
            ->get(['brands.id', 'brands.name', 'brands.is_default', 'brands.logo_id', 'brands.logo_dark_id', 'brands.logo_path', 'brands.logo_dark_path', 'brands.primary_color'])
            ->map($mapBrand)
            ->values()
            ->all();
    }
}
