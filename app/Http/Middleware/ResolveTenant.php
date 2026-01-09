<?php

namespace App\Http\Middleware;

use App\Models\Brand;
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
        $tenantId = session('tenant_id');

        if (! $tenantId) {
            abort(404, 'Tenant not found in session');
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
                // Verify user has access to this brand (unless owner/admin)
                if (! $isTenantOwnerOrAdmin) {
                    $hasBrandAccess = $user->brands()
                        ->where('brands.id', $brand->id)
                        ->where('tenant_id', $tenant->id)
                        ->exists();
                    
                    \Log::info('ResolveTenant - Brand access check', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'brand_id' => $brand->id,
                        'brand_name' => $brand->name,
                        'has_brand_access' => $hasBrandAccess,
                        'tenant_role' => $tenantRole,
                    ]);
                    
                    // Also check the database directly for debugging
                    $pivotRecord = \DB::table('brand_user')
                        ->where('user_id', $user->id)
                        ->where('brand_id', $brand->id)
                        ->first();
                    
                    \Log::info('ResolveTenant - Direct DB check', [
                        'user_id' => $user->id,
                        'brand_id' => $brand->id,
                        'pivot_record' => $pivotRecord ? [
                            'id' => $pivotRecord->id,
                            'role' => $pivotRecord->role,
                        ] : null,
                    ]);
                    
                    if (! $hasBrandAccess) {
                        \Log::warning('ResolveTenant - User does not have brand access, resetting', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                            'brand_id' => $brand->id,
                            'brand_name' => $brand->name,
                        ]);
                        // User doesn't have access to this brand - reset to a brand they do have access to
                        $userBrand = $user->brands()
                            ->where('tenant_id', $tenant->id)
                            ->first();
                        
                        if ($userBrand) {
                            $brand = $userBrand;
                            // Update session with accessible brand
                            session(['brand_id' => $brand->id]);
                        } else {
                            // User has no brand access - redirect to error page
                            // Don't allow access to default brand if user isn't assigned to any brand
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
            // No brand in session, try to use a brand the user has access to
            if ($user && ! $isTenantOwnerOrAdmin) {
                $userBrand = $user->brands()
                    ->where('tenant_id', $tenant->id)
                    ->first();
                
                if ($userBrand) {
                    $brand = $userBrand;
                    session(['brand_id' => $brand->id]);
                } else {
                    // User has no brand access - redirect to error page
                    // Don't allow access to default brand if user isn't assigned to any brand
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
