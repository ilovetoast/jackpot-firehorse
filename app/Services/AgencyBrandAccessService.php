<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Managed client tenant ids the user may open under an agency workspace: rows in tenant_agencies
     * plus memberships incubated via tenant_user (is_agency_managed + agency_tenant_id) even when no
     * tenant_agencies row exists yet. Aligns portfolio pickers with {@see AppNav} agency strip visibility.
     *
     * @return Collection<int, int>
     */
    public function accessibleManagedClientTenantIdsForAgency(User $user, Tenant $agencyTenant): Collection
    {
        if (! $agencyTenant->is_agency) {
            return collect();
        }

        $user->loadMissing('tenants');

        $linkedIds = TenantAgency::query()
            ->where('agency_tenant_id', $agencyTenant->id)
            ->pluck('tenant_id');

        $memberIds = $user->tenants->pluck('id');
        $fromLinks = $linkedIds->intersect($memberIds);

        $fromPivot = collect();
        foreach ($user->tenants as $t) {
            if ((int) $t->id === (int) $agencyTenant->id) {
                continue;
            }
            $p = $t->pivot;
            if (($p->is_agency_managed ?? false) && (int) ($p->agency_tenant_id ?? 0) === (int) $agencyTenant->id) {
                $fromPivot->push((int) $t->id);
            }
        }

        return $fromLinks->merge($fromPivot)->unique()->values();
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

        $accessibleIds = $this->accessibleManagedClientTenantIdsForAgency($user, $agency);
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
    /**
     * @param  Collection<int, Collection<int, Brand>>|null  $brandsByTenant  Preloaded from {@see loadBrandsGroupedForTenantIds()}
     * @param  array<string, true>|null  $allowedBrandIdSet  From {@see allowedBrandIdsForUserAmongBrands()}; required for non–tenant-wide when batching
     */
    public function managedAgencyClientsForUser(
        User $user,
        Tenant $agencyTenant,
        ?Collection $brandsByTenant = null,
        ?array $allowedBrandIdSet = null
    ): array {
        if (! $agencyTenant->is_agency) {
            return [];
        }

        $accessibleIds = $this->accessibleManagedClientTenantIdsForAgency($user, $agencyTenant);
        if ($accessibleIds->isEmpty()) {
            return [];
        }

        $links = TenantAgency::query()
            ->where('agency_tenant_id', $agencyTenant->id)
            ->get(['id', 'tenant_id']);
        $tenantAgencyIdByClientId = $links->pluck('id', 'tenant_id');

        if ($brandsByTenant === null) {
            $brandsByTenant = $this->loadBrandsGroupedForTenantIds($accessibleIds->all());
            $allowedBrandIdSet = $this->allowedBrandIdsForUserAmongBrands($user, $brandsByTenant);
        } elseif ($allowedBrandIdSet === null) {
            $allowedBrandIdSet = $this->allowedBrandIdsForUserAmongBrands($user, $brandsByTenant);
        }

        return Tenant::query()
            ->whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(function (Tenant $t) use ($user, $tenantAgencyIdByClientId, $brandsByTenant, $allowedBrandIdSet) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'tenant_agency_id' => $tenantAgencyIdByClientId[$t->id] ?? null,
                    'brands' => $this->brandsUserCanOpenInClientTenant(
                        $user,
                        $t,
                        $brandsByTenant->get($t->id, collect()),
                        $allowedBrandIdSet
                    ),
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

        $accessibleClientIds = $this->accessibleManagedClientTenantIdsForAgency($user, $agency);

        $tenantIdsForBatch = collect([$agency->id])->merge($accessibleClientIds)->unique()->values()->all();
        $brandsByTenant = $this->loadBrandsGroupedForTenantIds($tenantIdsForBatch);
        $allowedBrandIdSet = $this->allowedBrandIdsForUserAmongBrands($user, $brandsByTenant);

        $flat = [];

        foreach ($this->brandsUserCanOpenInClientTenant($user, $agency, $brandsByTenant->get($agency->id, collect()), $allowedBrandIdSet) as $b) {
            $flat[] = array_merge($b, [
                'tenant_id' => $agency->id,
                'tenant_slug' => $agency->slug,
            ]);
        }

        foreach ($this->managedAgencyClientsForUser($user, $agency, $brandsByTenant, $allowedBrandIdSet) as $client) {
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
     * Grouped agency portfolio for the unified nav context picker (agency tenant brands + managed client workspaces).
     * Returns null when the user has no agency tenant membership.
     *
     * @return array{
     *     agency_tenant_id: int,
     *     agency_tenant_name: string,
     *     agency_brands: array<int, array<string, mixed>>,
     *     client_workspaces: array<int, array{tenant_id: int, tenant_name: string, brands: array<int, array<string, mixed>>}>
     * }|null
     */
    public function groupedAgencyPortfolioBrands(User $user): ?array
    {
        $user->loadMissing('tenants');
        $user->warmTenantRoleCacheFromLoadedTenants();

        $agency = $this->agencyTenantForUser($user);
        if (! $agency) {
            return null;
        }

        $accessibleClientIds = $this->accessibleManagedClientTenantIdsForAgency($user, $agency);

        $tenantIdsForBatch = collect([$agency->id])->merge($accessibleClientIds)->unique()->values()->all();
        $brandsByTenant = $this->loadBrandsGroupedForTenantIds($tenantIdsForBatch);
        $allowedBrandIdSet = $this->allowedBrandIdsForUserAmongBrands($user, $brandsByTenant);

        $agencyBrands = $this->brandsUserCanOpenInClientTenant(
            $user,
            $agency,
            $brandsByTenant->get($agency->id, collect()),
            $allowedBrandIdSet
        );

        $clientIdsSorted = $accessibleClientIds->values()->all();
        usort($clientIdsSorted, function ($a, $b) use ($user) {
            $na = (string) ($user->tenants->firstWhere('id', $a)?->name ?? '');
            $nb = (string) ($user->tenants->firstWhere('id', $b)?->name ?? '');

            return strcasecmp($na, $nb);
        });

        $clientWorkspaces = [];
        foreach ($clientIdsSorted as $clientId) {
            $clientTenant = $user->tenants->firstWhere('id', $clientId);
            if (! $clientTenant) {
                continue;
            }
            $brands = $this->brandsUserCanOpenInClientTenant(
                $user,
                $clientTenant,
                $brandsByTenant->get($clientId, collect()),
                $allowedBrandIdSet
            );
            if ($brands === []) {
                continue;
            }
            $clientWorkspaces[] = [
                'tenant_id' => (int) $clientId,
                'tenant_name' => (string) $clientTenant->name,
                'brands' => $brands,
            ];
        }

        return [
            'agency_tenant_id' => (int) $agency->id,
            'agency_tenant_name' => (string) $agency->name,
            'agency_brands' => $agencyBrands,
            'client_workspaces' => $clientWorkspaces,
        ];
    }

    /**
     * Brands the user may open when switching into a tenant (tenant admins see all brands).
     *
     * @param  Collection<int, Brand>|null  $brands  Preloaded brands for this tenant (avoids N per-tenant queries)
     * @param  array<string, true>|null  $allowedBrandIdSet  Active brand_user brand ids (keys as string); used when not tenant-wide and batching
     * @return array<int, array{id: int, name: string, is_default: bool, logo_url: ?string, logo_dark_url: ?string, primary_color: ?string}>
     */
    protected function brandsUserCanOpenInClientTenant(
        User $user,
        Tenant $clientTenant,
        ?Collection $brands = null,
        ?array $allowedBrandIdSet = null
    ): array {
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

        if ($brands !== null) {
            if ($brands->isEmpty()) {
                return [];
            }

            if ($tenantWide) {
                return $brands->sortBy('name')->values()->map($mapBrand)->values()->all();
            }

            $allowedBrandIdSet ??= [];
            $filtered = $brands->filter(function (Brand $b) use ($allowedBrandIdSet) {
                return isset($allowedBrandIdSet[(string) $b->id]);
            });

            return $filtered->sortBy('name')->values()->map($mapBrand)->values()->all();
        }

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

    /**
     * One query: all brands for many tenants, grouped by tenant_id (fixes N+1 in agency strip / Inertia share).
     *
     * @param  list<int|string>  $tenantIds
     * @return Collection<int|string, Collection<int, Brand>>
     */
    protected function loadBrandsGroupedForTenantIds(array $tenantIds): Collection
    {
        if ($tenantIds === []) {
            return collect();
        }

        return Brand::query()
            ->whereIn('tenant_id', $tenantIds)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'is_default',
                'logo_id',
                'logo_dark_id',
                'logo_path',
                'logo_dark_path',
                'primary_color',
                'tenant_id',
            ])
            ->groupBy('tenant_id');
    }

    /**
     * Single brand_user query for all candidate brand ids (member path when batching).
     *
     * @param  Collection<int|string, Collection<int, Brand>>  $brandsByTenant
     * @return array<string, true>
     */
    protected function allowedBrandIdsForUserAmongBrands(User $user, Collection $brandsByTenant): array
    {
        $ids = $brandsByTenant->flatten()->pluck('id')->unique()->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $allowed = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->whereIn('brand_id', $ids->all())
            ->pluck('brand_id');

        $out = [];
        foreach ($allowed as $id) {
            $out[(string) $id] = true;
        }

        return $out;
    }
}
