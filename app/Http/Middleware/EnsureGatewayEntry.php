<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Tenant;
use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureGatewayEntry
{
    /**
     * Intent-aware gateway enforcement.
     *
     * Flow priority after gateway entry:
     *   1. intended_url (deep link the user tried before auth) — always wins
     *   2. portal_settings.entry.default_destination — fallback when no deep link
     *
     * This means auto_enter + default_destination = guidelines WILL NOT override
     * a user who originally deep-linked to /app/assets.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('app/*')) {
            return $next($request);
        }

        if ($request->is('app/errors/*') || $request->is('app/api/*')) {
            return $next($request);
        }

        if (! Auth::check()) {
            session(['intended_url' => $request->fullUrl()]);

            return redirect('/gateway');
        }

        $brandId = session('brand_id');
        $tenantId = session('tenant_id');

        if (! $brandId || ! $tenantId) {
            session(['intended_url' => $request->fullUrl()]);

            return redirect('/gateway');
        }

        // Validate brand still exists, belongs to tenant, and user still has access.
        // Catches: deleted brands, revoked membership, company switches in other tabs.
        $brand = Brand::where('id', $brandId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $brand) {
            session()->forget(['brand_id', 'tenant_id']);
            session(['intended_url' => $request->fullUrl()]);

            return redirect('/gateway');
        }

        $user = Auth::user();
        $tenant = Tenant::find($tenantId);

        // Brand plan limit enforcement: if the session brand is disabled by plan limit,
        // auto-redirect to the first enabled brand the user can access.
        if ($tenant) {
            $planService = app(PlanService::class);
            if ($planService->isBrandDisabledByPlanLimit($brand, $tenant)) {
                $enabledBrand = $planService->findFirstEnabledBrand($tenant, $user);

                if ($enabledBrand) {
                    session(['brand_id' => $enabledBrand->id]);
                    session()->flash('warning', "The brand \"{$brand->name}\" is unavailable on your current plan. You've been redirected to \"{$enabledBrand->name}\".");

                    return redirect($request->fullUrl());
                }

                // No enabled brands accessible — show error page
                session()->forget('brand_id');
                return redirect()->route('errors.brand-disabled');
            }
        }

        // Tenant owners/admins can access any brand — check pivot only for regular users
        $tenantRole = $user->tenants()
            ->where('tenants.id', $tenantId)
            ->first()?->pivot?->role;

        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        if (! $isTenantOwnerOrAdmin) {
            $hasAccess = $brand->users()
                ->where('users.id', $user->id)
                ->wherePivotNull('removed_at')
                ->exists();

            if (! $hasAccess) {
                session()->forget(['brand_id', 'tenant_id']);
                session(['intended_url' => $request->fullUrl()]);

                return redirect('/gateway');
            }
        }

        return $next($request);
    }
}
