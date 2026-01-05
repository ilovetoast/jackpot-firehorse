<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Tenant;
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
        $brandId = session('brand_id');
        
        if ($brandId) {
            $brand = Brand::where('id', $brandId)
                ->where('tenant_id', $tenant->id)
                ->first();
            
            if (! $brand) {
                // Brand doesn't exist or doesn't belong to tenant, use default
                $brand = $tenant->defaultBrand;
            }
        } else {
            // No brand in session, use default brand
            $brand = $tenant->defaultBrand;
        }

        if (! $brand) {
            abort(500, 'Tenant must have at least one brand');
        }

        // Bind brand to container
        app()->instance('brand', $brand);

        return $next($request);
    }
}
