<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
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

        // Account + company picker + sign-out must work with no tenant in session (e.g. user removed from all workspaces).
        if ($this->allowsWithoutTenantContext($request)) {
            return $next($request);
        }

        if (! Auth::check()) {
            session(['intended_url' => $request->fullUrl()]);

            return redirect('/gateway');
        }

        $brandId = session('brand_id');
        $tenantId = session('tenant_id');
        // Global `web` stack runs this middleware before route `ImpersonationMiddleware`, so Auth is still
        // the initiator. Workspace session (tenant/brand) belongs to the impersonation target — validate
        // membership with the acting user, not the staff account (otherwise we clear session → /gateway).
        $user = app(ImpersonationService::class)->actingUser() ?? Auth::user();

        if (! $tenantId) {
            session(['intended_url' => $request->fullUrl()]);

            return redirect('/gateway');
        }

        // C12: Collection-only (external) users — no brand_user row; tenant + accepted collection grant only.
        // Without this branch, every /app/* request loops to /gateway, which shows an empty brand picker.
        if (! $brandId) {
            $this->maybePrimeSingleCollectionGrantSession($user, (int) $tenantId);
            $collectionId = session('collection_id');
            if ($collectionId
                && $this->userHasNoBrandMembershipForTenant($user, (int) $tenantId)
                && $this->userHasAcceptedCollectionGrant($user, (int) $tenantId, (int) $collectionId)) {
                return $next($request);
            }

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

        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);

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

    /**
     * Routes that must not require tenant/brand session (detached users, company creation, profile edits).
     */
    private function allowsWithoutTenantContext(Request $request): bool
    {
        return $request->is('app/profile')
            || $request->is('app/profile/*')
            || $request->is('app/companies')
            || $request->is('app/companies/*')
            || $request->is('app/logout')
            // Command Center: site staff may open admin surfaces before picking a workspace.
            || $request->is('app/admin')
            || $request->is('app/admin/*');
    }

    /**
     * External collaborators only have collection_user grants — no brand pivot rows.
     */
    private function userHasNoBrandMembershipForTenant(User $user, int $tenantId): bool
    {
        return ! $user->brands()
            ->where('tenant_id', $tenantId)
            ->whereNull('brand_user.removed_at')
            ->exists();
    }

    private function userHasAcceptedCollectionGrant(User $user, int $tenantId, int $collectionId): bool
    {
        return $user->collectionAccessGrants()
            ->where('collection_id', $collectionId)
            ->whereNotNull('accepted_at')
            ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenantId))
            ->exists();
    }

    /**
     * If the user has exactly one collection grant in this tenant, pin session collection_id so ResolveTenant can enter collection-only mode.
     */
    private function maybePrimeSingleCollectionGrantSession(User $user, int $tenantId): void
    {
        if (session()->has('collection_id')) {
            return;
        }

        if (! $this->userHasNoBrandMembershipForTenant($user, $tenantId)) {
            return;
        }

        $ids = $user->collectionAccessGrants()
            ->whereNotNull('accepted_at')
            ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('collection_id')
            ->pluck('collection_id');

        if ($ids->count() === 1) {
            session(['collection_id' => (int) $ids->first()]);
        }
    }
}
