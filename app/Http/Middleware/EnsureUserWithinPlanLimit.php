<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserWithinPlanLimit
{
    /**
     * Handle an incoming request.
     * Blocks users from accessing a specific tenant where they are disabled due to plan limits.
     * Users can still access other tenants and company switching routes.
     * Owner is never blocked.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = session('tenant_id');

        // Only check for authenticated users with an active tenant
        // Allow access to company switching routes - these don't have tenant middleware
        if ($user && $tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            
            // Owner is NEVER blocked - check this FIRST
            if ($tenant && $tenant->isOwner($user)) {
                return $next($request);
            }
            
            // Check if user is disabled for THIS specific tenant (not blocking other tenants)
            if ($tenant && $user->isDisabledByPlanLimit($tenant)) {
                // Ensure tenant is bound for the error page
                if (!app()->bound('tenant')) {
                    app()->instance('tenant', $tenant);
                }
                
                // Allow access to company switching routes so user can switch to another tenant
                // Block access to routes within this disabled tenant
                $routeName = $request->route()?->getName();
                $allowedRoutes = ['companies.index', 'companies.switch'];
                
                if ($routeName && in_array($routeName, $allowedRoutes)) {
                    // Allow company switching
                    return $next($request);
                }
                
                // User is disabled for this tenant - redirect to error page
                // But show link to switch companies
                return redirect()->route('errors.user-limit-exceeded');
            }
        }

        return $next($request);
    }
}
