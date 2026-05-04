<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Support\TenantMailBranding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip brand resolution for error pages to prevent redirect loops
        if ($request->routeIs('errors.no-brand-assignment') || $request->routeIs('errors.brand-disabled')) {
            $tenantId = session('tenant_id');
            if ($tenantId) {
                $tenant = Tenant::find($tenantId);
                if ($tenant) {
                    $tenant->loadMissing(['defaultBrand', 'brands']);
                    app()->instance('tenant', $tenant);
                }
            }

            $this->applyTenantMailBrandingForRequest();

            return $next($request);
        }

        $tenantId = session('tenant_id');

        if (! $tenantId) {
            // Redirect to companies page instead of showing 404
            // This happens when user hasn't selected a company/tenant yet
            $user = $request->user();

            // If user has no tenants at all, redirect to error page
            if ($user && $user->tenants()->count() === 0) {
                return redirect()->route('errors.no-companies');
            }

            // User has tenants but none selected - redirect to selection page
            return redirect()->route('companies.index');
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        // Lazy loading is disabled app-wide; this middleware reads brands + defaultBrand below.
        $tenant->loadMissing(['defaultBrand', 'brands']);

        // Verify authenticated user belongs to tenant (load tenants once to avoid N+1 in policies)
        $user = $request->user();
        if ($user) {
            $user->loadMissing('tenants');
            if (! $user->belongsToTenant($tenant->id)) {
                abort(403, 'User does not belong to this tenant');
            }
        }

        // Bind tenant to container
        app()->instance('tenant', $tenant);

        // Resolve active brand ($user already set above when verifying tenant membership)
        $user = $user ?? $request->user();

        // C12: Match session to the URL collection before reading brand_id (avoids wrong collection_only + 403 chains).
        if ($user && $request->routeIs(['collection-invite.landing', 'collection-invite.view'])) {
            $routeCollection = $request->route('collection');
            if ($routeCollection instanceof Collection && (int) $routeCollection->tenant_id === (int) $tenant->id) {
                $hasGrant = $user->collectionAccessGrants()
                    ->where('collection_id', $routeCollection->id)
                    ->whereNotNull('accepted_at')
                    ->exists();
                $noBrandInTenant = ! $user->brands()->where('tenant_id', $tenant->id)->whereNull('brand_user.removed_at')->exists();
                if ($hasGrant && $noBrandInTenant) {
                    session(['collection_id' => $routeCollection->id]);
                    session()->forget('brand_id');
                }
            }
        }

        $brandId = session('brand_id');

        // Get user's tenant role to determine access
        $tenantRole = null;
        $isTenantOwnerOrAdmin = false;
        if ($user) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);
        }

        if ($brandId) {
            $brand = Brand::where('id', $brandId)
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($brand && $user) {
                // Phase MI-1: Verify user has active brand membership (unless owner/admin)
                if (! $isTenantOwnerOrAdmin) {
                    $hasBrandAccess = $user->hasActiveBrandUserAssignment($brand);

                    \Log::info('ResolveTenant - Active membership check', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'brand_id' => $brand->id,
                        'brand_name' => $brand->name,
                        'has_active_membership' => $hasBrandAccess,
                        'tenant_role' => $tenantRole,
                    ]);

                    if (! $hasBrandAccess) {
                        \Log::warning('ResolveTenant - User does not have brand access, resetting', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                            'brand_id' => $brand->id,
                            'brand_name' => $brand->name,
                        ]);
                        // Phase MI-1: User doesn't have active membership - find a brand they do have access to
                        $userBrand = null;
                        foreach ($tenant->brands->sortBy([
                            fn ($b) => ($b->is_default ?? false) ? 0 : 1,
                            fn ($b) => strtolower((string) ($b->name ?? '')),
                        ]) as $tenantBrand) {
                            if ($user->hasActiveBrandUserAssignment($tenantBrand)) {
                                $userBrand = $tenantBrand;
                                break;
                            }
                        }

                        if ($userBrand) {
                            $brand = $userBrand;
                            // Update session with accessible brand
                            session(['brand_id' => $brand->id]);
                        } else {
                            // C12: Collection-only mode — user has no brand but may have collection access grant
                            $collectionId = session('collection_id');
                            if ($collectionId && $user) {
                                $collection = Collection::where('id', $collectionId)
                                    ->where('tenant_id', $tenant->id)
                                    ->first();
                                if ($collection && $user->collectionAccessGrants()->where('collection_id', $collection->id)->whereNotNull('accepted_at')->exists()) {
                                    app()->instance('collection_only', true);
                                    app()->instance('collection', $collection);

                                    $this->applyTenantMailBrandingForRequest();

                                    return $next($request);
                                }
                            }
                            session()->forget('brand_id');

                            return redirect()->route('errors.no-brand-assignment');
                        }
                    }
                }
            }

            if (! $brand) {
                // Brand doesn't exist or doesn't belong to tenant, use default
                $brand = $tenant->defaultBrand;
                if ($brand) {
                    session(['brand_id' => $brand->id]);
                }
            }
        } else {
            // Phase MI-1: No brand in session, try to use a brand the user has active membership for
            if ($user && ! $isTenantOwnerOrAdmin) {
                $userBrand = null;
                foreach ($tenant->brands->sortBy([
                    fn ($b) => ($b->is_default ?? false) ? 0 : 1,
                    fn ($b) => strtolower((string) ($b->name ?? '')),
                ]) as $tenantBrand) {
                    if ($user->hasActiveBrandUserAssignment($tenantBrand)) {
                        $userBrand = $tenantBrand;
                        break;
                    }
                }

                if ($userBrand) {
                    $brand = $userBrand;
                    session(['brand_id' => $brand->id]);
                } else {
                    // C12: Collection-only mode
                    $collectionId = session('collection_id');
                    if ($collectionId && $user) {
                        $collection = Collection::where('id', $collectionId)
                            ->where('tenant_id', $tenant->id)
                            ->first();
                        if ($collection && $user->collectionAccessGrants()->where('collection_id', $collection->id)->whereNotNull('accepted_at')->exists()) {
                            app()->instance('collection_only', true);
                            app()->instance('collection', $collection);

                            $this->applyTenantMailBrandingForRequest();

                            return $next($request);
                        }
                    }
                    session()->forget('brand_id');

                    return redirect()->route('errors.no-brand-assignment');
                }
            } else {
                // Owner/admin or no user - use default brand
                $brand = $tenant->defaultBrand;
                if ($brand) {
                    session(['brand_id' => $brand->id]);
                }
            }
        }

        if (! $brand) {
            abort(500, 'Tenant must have at least one brand');
        }

        // Final security check: For non-owner/admin users, verify they have access to the resolved brand
        // This prevents access even if session has a stale brand_id
        if ($user && $brand) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);

            if (! $isTenantOwnerOrAdmin) {
                $hasBrandAccess = $user->brands()
                    ->where('brands.id', $brand->id)
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('brand_user.removed_at')
                    ->exists();

                if (! $hasBrandAccess) {
                    // User doesn't have access - find a brand they do have access to
                    $userBrand = $user->brands()
                        ->where('tenant_id', $tenant->id)
                        ->whereNull('brand_user.removed_at')
                        ->orderByDesc('is_default')
                        ->orderBy('name')
                        ->first();

                    if ($userBrand) {
                        $brand = $userBrand;
                        session(['brand_id' => $brand->id]);
                    } else {
                        // No accessible brand - redirect to error page
                        // Clear the brand_id from session to prevent loop
                        session()->forget('brand_id');

                        return redirect()->route('errors.no-brand-assignment');
                    }
                }
            }
        }

        // Bind brand to container
        app()->instance('brand', $brand);

        $this->applyTenantMailBrandingForRequest();

        return $next($request);
    }

    /**
     * Staging: fixed From address + tenant display name (see TenantMailBranding).
     * Kept inside ResolveTenant so tenant routes do not depend on a separate middleware class.
     */
    private function applyTenantMailBrandingForRequest(): void
    {
        if (TenantMailBranding::enabled() && app()->bound('tenant')) {
            TenantMailBranding::apply(app('tenant'));
        }
    }
}
