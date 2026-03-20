<?php

namespace App\Services\BrandGateway;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantInvitation;
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
     *  6. Fallback to null (default Jackpot branding)
     */
    public function resolve(Request $request, ?string $inviteToken = null): array
    {
        $user = Auth::user();
        $tenant = null;
        $brand = null;
        $invitation = null;

        // 1. Subdomain resolution
        if (! $tenant) {
            $tenant = $this->resolveFromSubdomain($request);
        }

        // 2. URL param resolution
        if (! $tenant) {
            $tenant = $this->resolveFromUrlParams($request, 'company');
        }
        if (! $brand && $request->query('brand')) {
            $brand = $this->resolveBrandFromSlug($request->query('brand'), $tenant);
            if ($brand && ! $tenant) {
                $tenant = $brand->tenant;
            }
        }

        // 3. Invite token resolution
        if (! $tenant && $inviteToken) {
            [$tenant, $invitation] = $this->resolveFromInviteToken($inviteToken);
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

        if ($tenant && $user) {
            $availableBrands = $this->getAvailableBrands($user, $tenant);

            if (! $brand && count($availableBrands) === 1) {
                $brand = Brand::find($availableBrands[0]['id']);
            }
        } elseif ($tenant) {
            $brand = $brand ?? $tenant->defaultBrand;
        }

        return [
            'tenant' => $tenant ? $this->serializeTenant($tenant) : null,
            'brand' => $brand ? $this->serializeBrand($brand) : null,
            'invitation' => $invitation ? $this->serializeInvitation($invitation) : null,
            'available_companies' => $availableCompanies,
            'available_brands' => $availableBrands,
            'is_multi_company' => count($availableCompanies) > 1,
            'is_multi_brand' => count($availableBrands) > 1,
            'is_authenticated' => $user !== null,
        ];
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
        if (preg_match('/^([a-z0-9-]+)\.' . $escapedDomain . '$/', $host, $matches)) {
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

    protected function resolveFromInviteToken(string $token): array
    {
        $invitation = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with('tenant')
            ->first();

        if (! $invitation) {
            return [null, null];
        }

        return [$invitation->tenant, $invitation];
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
                ->where(function ($q) use ($tenant) {
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
            'logo_url' => $logoUrl,
            'primary_color' => $defaultBrand?->primary_color,
            'icon' => $defaultBrand?->icon,
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
            'icon_path' => $brand->icon_path,
            'icon' => $brand->icon,
            'icon_bg_color' => $brand->icon_bg_color,
            'icon_style' => $brand->icon_style ?? 'subtle',
            'primary_color' => $brand->primary_color,
            'secondary_color' => $brand->secondary_color,
            'accent_color' => $brand->accent_color,
            'nav_color' => $brand->nav_color,
            'is_default' => (bool) $brand->is_default,
        ];
    }

    protected function serializeInvitation(TenantInvitation $invitation): array
    {
        return [
            'token' => $invitation->token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'tenant_name' => $invitation->tenant?->name,
            'inviter_name' => $invitation->inviter?->name,
            'brand_assignments' => $invitation->brand_assignments,
        ];
    }
}
