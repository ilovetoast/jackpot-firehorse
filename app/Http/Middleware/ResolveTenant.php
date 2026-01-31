<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        if ($request->routeIs('errors.no-brand-assignment')) {
            $tenantId = session('tenant_id');
            if ($tenantId) {
                $tenant = Tenant::find($tenantId);
                if ($tenant) {
                    app()->instance('tenant', $tenant);
                }
            }
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

        // Verify authenticated user belongs to tenant
        if ($request->user() && ! $request->user()->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'User does not belong to this tenant');
        }

        // Bind tenant to container
        app()->instance('tenant', $tenant);

        // Resolve active brand
        $user = $request->user();
        $brandId = session('brand_id');
        
        // Get user's tenant role to determine access
        $tenantRole = null;
        $isTenantOwnerOrAdmin = false;
        if ($user) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        }
        
        if ($brandId) {
            $brand = Brand::where('id', $brandId)
                ->where('tenant_id', $tenant->id)
                ->first();
            
            if ($brand && $user) {
                // Phase MI-1: Verify user has active brand membership (unless owner/admin)
                if (! $isTenantOwnerOrAdmin) {
                    $membership = $user->activeBrandMembership($brand);
                    $hasBrandAccess = $membership !== null;
                    
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
                        foreach ($tenant->brands as $tenantBrand) {
                            if ($user->activeBrandMembership($tenantBrand)) {
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
                foreach ($tenant->brands as $tenantBrand) {
                    if ($user->activeBrandMembership($tenantBrand)) {
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
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            if (!$isTenantOwnerOrAdmin) {
                $hasBrandAccess = $user->brands()
                    ->where('brands.id', $brand->id)
                    ->where('tenant_id', $tenant->id)
                    ->exists();
                
                if (!$hasBrandAccess) {
                    // User doesn't have access - find a brand they do have access to
                    $userBrand = $user->brands()
                        ->where('tenant_id', $tenant->id)
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

        return $next($request);
    }
}
