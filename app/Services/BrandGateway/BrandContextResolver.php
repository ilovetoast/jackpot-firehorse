<?php

namespace App\Services\BrandGateway;

use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\GatewayResumeCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrandContextResolver
{
    /**
     * Resolve the brand/tenant context for the gateway.
     *
     * Resolution order:
     *  1. Subdomain (company slug)
     *  2. URL param (?brand= or ?company=)
     *  3. Invite token
     *  4. Session (last_company_id, last_brand_id)
     *  5. Authenticated user memberships
     *  6. Plain /gateway (no ?company / ?tenant / ?brand, no company subdomain): merge **all** brands across every company the user belongs to (`brand_picker_scope === all_workspaces`), with session tenant brands listed first when possible
     *  7. Fallback to null (default Jackpot branding)
     */
    public function resolve(Request $request, ?string $inviteToken = null): array
    {
        $user = Auth::user();
        $tenant = null;
        $brand = null;

        // 1. Subdomain resolution (locks brand list to that workspace when present)
        $subdomainTenant = $this->resolveFromSubdomain($request);
        if (! $tenant) {
            $tenant = $subdomainTenant;
        }

        // 2. URL param resolution (?company= or ?tenant= slug/id — legacy login used ?tenant=)
        if (! $tenant) {
            $tenant = $this->resolveFromUrlParams($request, 'company');
        }
        if (! $tenant) {
            $tenant = $this->resolveFromUrlParams($request, 'tenant');
        }
        if (! $brand && $request->query('brand')) {
            $brand = $this->resolveBrandFromSlug($request->query('brand'), $tenant);
            if ($brand && ! $tenant) {
                $tenant = $brand->tenant;
            }
        }

        // 3. Invite token resolution (tenant invite or brand invite — brand wins theme for single-brand flows)
        $invitationModel = null;
        if (! $tenant && $inviteToken) {
            [$tenant, $brandFromInvite, $invitationModel] = $this->resolveFromInviteToken($inviteToken);
            if ($brandFromInvite) {
                $brand = $brandFromInvite;
            }
        }

        // 4. Session resolution (tenant + brand)
        if (! $tenant && $user) {
            $tenant = $this->resolveFromSession();
        }
        if (! $brand && $user && $tenant) {
            $brand = $this->resolveBrandFromSession($tenant);
        }

        // 5. Authenticated user memberships
        $availableCompanies = [];
        $availableBrands = [];

        if ($user) {
            $availableCompanies = $this->getAvailableCompanies($user);

            if (! $tenant && count($availableCompanies) === 1) {
                $tenant = Tenant::find($availableCompanies[0]['id']);
            }
        }

        // Drop tenant/brand that no longer matches membership (e.g. removed from company, stale session).
        // Skip when resolving an invite URL so pending invites still show the target org before accept.
        if ($user && $tenant !== null && $inviteToken === null) {
            $tenantIds = array_column($availableCompanies, 'id');
            if ($availableCompanies === [] || ! in_array((int) $tenant->id, array_map('intval', $tenantIds), true)) {
                $tenant = null;
                $brand = null;
            }
        }

        if ($tenant && $user) {
            $availableBrands = $this->getAvailableBrands($user, $tenant);

            if (! $brand && count($availableBrands) === 1) {
                $brand = Brand::find($availableBrands[0]['id']);
            }
        } elseif ($tenant) {
            $brand = $brand ?? $tenant->defaultBrand;
        }

        $gatewayResumeActive = false;

        if ($user !== null && $inviteToken === null) {
            $resume = GatewayResumeCookie::tryDecodeAndAuthorize(
                $request,
                $user,
                $availableCompanies,
            );
            if ($resume) {
                $tenant = $resume['tenant'];
                $brand = $resume['brand'];
                $availableBrands = $this->getAvailableBrands($user, $tenant);
                $gatewayResumeActive = true;
            }
        }

        $brandPickerScope = 'tenant';

        if ($user !== null
            && $inviteToken === null
            && ! $gatewayResumeActive
            && $availableCompanies !== []
            && ! $this->isGatewayBrandListScopedToSingleWorkspace($request, $subdomainTenant)) {
            $allBrands = $this->getAllAccessibleBrandsAcrossTenants(
                $user,
                $availableCompanies,
                session('tenant_id') ? (int) session('tenant_id') : null,
            );

            if (count($allBrands) > 0) {
                $availableBrands = $allBrands;
                $brandPickerScope = 'all_workspaces';

                if (count($allBrands) === 1 && ! $brand) {
                    $tenant = Tenant::find((int) $allBrands[0]['tenant_id']);
                    $brand = $tenant ? Brand::find((int) $allBrands[0]['id']) : null;
                }
            }
        }

        return [
            'tenant' => $tenant ? $this->serializeTenant($tenant) : null,
            'brand' => $brand ? $this->serializeBrand($brand) : null,
            'invitation' => $invitationModel ? $this->serializeInvitationModel($invitationModel) : null,
            'available_companies' => $availableCompanies,
            'available_brands' => $availableBrands,
            'is_multi_company' => count($availableCompanies) > 1,
            'is_multi_brand' => count($availableBrands) > 1,
            'is_authenticated' => $user !== null,
            /** Logged-in user belongs to the resolved tenant but has zero brand memberships (gateway brand picker empty). */
            'tenant_member_without_brands' => $user !== null && $tenant !== null && count($availableBrands) === 0
                && $brandPickerScope !== 'all_workspaces',
            /** True when a valid jp_gateway_resume cookie pinned tenant+brand (cinematic enter despite multi-brand). */
            'gateway_resume_active' => $gatewayResumeActive,
            /**
             * all_workspaces: GET /gateway lists every brand the user can open across companies (unless URL/subdomain scopes the list).
             */
            'brand_picker_scope' => $brandPickerScope,
        ];
    }

    /**
     * When true, keep the brand picker scoped to the resolved workspace (subdomain or explicit query).
     */
    protected function isGatewayBrandListScopedToSingleWorkspace(Request $request, ?Tenant $subdomainTenant): bool
    {
        if ($subdomainTenant !== null) {
            return true;
        }

        return $request->filled('company')
            || $request->filled('tenant')
            || $request->filled('brand');
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableCompanies
     * @return array<int, array<string, mixed>>
     */
    protected function getAllAccessibleBrandsAcrossTenants(User $user, array $availableCompanies, ?int $prioritizeTenantId): array
    {
        $merged = [];

        foreach ($availableCompanies as $c) {
            $tid = (int) ($c['id'] ?? 0);
            if ($tid === 0) {
                continue;
            }

            $tenantModel = Tenant::find($tid);
            if (! $tenantModel) {
                continue;
            }

            foreach ($this->getAvailableBrands($user, $tenantModel) as $row) {
                $merged[] = array_merge($row, [
                    'tenant_id' => $tenantModel->id,
                    'tenant_name' => $tenantModel->name,
                    'tenant_slug' => $tenantModel->slug,
                ]);
            }
        }

        usort($merged, function (array $a, array $b) use ($prioritizeTenantId): int {
            $at = (int) ($a['tenant_id'] ?? 0);
            $bt = (int) ($b['tenant_id'] ?? 0);

            if ($prioritizeTenantId) {
                $aPri = $at === $prioritizeTenantId ? 0 : 1;
                $bPri = $bt === $prioritizeTenantId ? 0 : 1;
                if ($aPri !== $bPri) {
                    return $aPri <=> $bPri;
                }
            }

            $nameCmp = strcasecmp((string) ($a['tenant_name'] ?? ''), (string) ($b['tenant_name'] ?? ''));
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_values($merged);
    }

    protected function resolveFromSubdomain(Request $request): ?Tenant
    {
        if (! config('subdomain.enabled')) {
            return null;
        }

        $host = $request->getHost();
        $mainDomain = config('subdomain.main_domain');

        if ($host === $mainDomain) {
            return null;
        }

        $escapedDomain = preg_quote($mainDomain, '/');
        if (preg_match('/^([a-z0-9-]+)\.'.$escapedDomain.'$/', $host, $matches)) {
            $slug = $matches[1];
            $reserved = config('subdomain.reserved_slugs', []);
            if (in_array($slug, $reserved, true)) {
                return null;
            }

            return Tenant::where('slug', $slug)->first();
        }

        return null;
    }

    protected function resolveFromUrlParams(Request $request, string $param): ?Tenant
    {
        $value = $request->query($param);
        if (! $value) {
            return null;
        }

        return Tenant::where('slug', $value)->orWhere('id', $value)->first();
    }

    protected function resolveBrandFromSlug(string $slug, ?Tenant $tenant): ?Brand
    {
        $query = Brand::where('slug', $slug);
        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        return $query->first();
    }

    /**
     * @return array{0: ?Tenant, 1: ?Brand, 2: TenantInvitation|BrandInvitation|null}
     */
    protected function resolveFromInviteToken(string $token): array
    {
        $tenantInv = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with(['tenant', 'inviter'])
            ->first();

        if ($tenantInv) {
            return [$tenantInv->tenant, null, $tenantInv];
        }

        $brandInv = BrandInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with(['brand.tenant', 'inviter'])
            ->first();

        if (! $brandInv || ! $brandInv->brand) {
            return [null, null, null];
        }

        return [$brandInv->brand->tenant, $brandInv->brand, $brandInv];
    }

    protected function resolveFromSession(): ?Tenant
    {
        $tenantId = session('tenant_id');
        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        return null;
    }

    protected function resolveBrandFromSession(Tenant $tenant): ?Brand
    {
        $brandId = session('brand_id');
        if (! $brandId) {
            return null;
        }

        return Brand::where('id', $brandId)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    protected function getAvailableCompanies($user): array
    {
        return $user->tenants()
            ->with('defaultBrand')
            ->get()
            ->map(fn (Tenant $t) => $this->serializeTenant($t))
            ->values()
            ->toArray();
    }

    protected function getAvailableBrands($user, Tenant $tenant): array
    {
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        $planService = app(\App\Services\PlanService::class);
        $brandLimitInfo = $planService->getBrandLimitInfo($tenant);
        $disabledIds = $brandLimitInfo['disabled'];

        if ($isTenantOwnerOrAdmin) {
            $brands = $tenant->brands()
                ->where(function ($q) {
                    $q->where('show_in_selector', '!=', false)
                        ->orWhereNull('show_in_selector');
                })
                ->where('tenant_id', $tenant->id)
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        } else {
            $brands = $user->brands()
                ->where('tenant_id', $tenant->id)
                ->whereNull('brand_user.removed_at')
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        }

        return $brands
            ->map(fn (Brand $b) => array_merge(
                $this->serializeBrand($b),
                ['is_disabled' => in_array($b->id, $disabledIds)]
            ))
            ->values()
            ->toArray();
    }

    protected function serializeTenant(Tenant $tenant): array
    {
        $defaultBrand = $tenant->relationLoaded('defaultBrand')
            ? $tenant->defaultBrand
            : $tenant->defaultBrand()->first();

        $logoUrl = null;
        if ($defaultBrand) {
            try {
                $logo = $defaultBrand->logo_path;
                $logoUrl = ($logo !== null && $logo !== '') ? $logo : null;
            } catch (\Throwable) {
                $logoUrl = null;
            }
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_agency' => (bool) $tenant->is_agency,
            'logo_url' => $logoUrl,
            'primary_color' => $defaultBrand?->primary_color,
            'icon_bg_color' => $defaultBrand?->icon_bg_color,
            'icon_style' => $defaultBrand?->icon_style ?? 'subtle',
        ];
    }

    protected function serializeBrand(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'logo_path' => $brand->logo_path,
            'logo_dark_path' => $brand->logo_dark_path,
            'icon_bg_color' => $brand->icon_bg_color,
            'icon_style' => $brand->icon_style ?? 'subtle',
            'primary_color' => $brand->primary_color,
            'secondary_color' => $brand->secondary_color,
            'accent_color' => $brand->accent_color,
            'nav_color' => $brand->nav_color,
            'is_default' => (bool) $brand->is_default,
        ];
    }

    protected function serializeInvitationModel(TenantInvitation|BrandInvitation $invitation): array
    {
        return $invitation instanceof BrandInvitation
            ? $this->serializeBrandInvitation($invitation)
            : $this->serializeTenantInvitation($invitation);
    }

    protected function serializeTenantInvitation(TenantInvitation $invitation): array
    {
        return [
            'kind' => 'tenant',
            'token' => $invitation->token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'tenant_name' => $invitation->tenant?->name,
            'inviter_name' => $invitation->inviter?->name,
            'brand_assignments' => $invitation->brand_assignments,
        ];
    }

    protected function serializeBrandInvitation(BrandInvitation $invitation): array
    {
        $tenant = $invitation->brand?->tenant;

        return [
            'kind' => 'brand',
            'token' => $invitation->token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'tenant_name' => $tenant?->name,
            'brand_id' => $invitation->brand_id,
            'brand_name' => $invitation->brand?->name,
            'inviter_name' => $invitation->inviter?->name,
            'brand_assignments' => [],
        ];
    }
}
