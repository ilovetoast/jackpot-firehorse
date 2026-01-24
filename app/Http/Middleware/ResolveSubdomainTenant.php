<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Tenant;

class ResolveSubdomainTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantSlug = $this->extractTenantSlug($request);
        
        // If subdomains are disabled or we're on the main domain, continue normally  
        if (!config('subdomain.enabled')) {
            return $next($request);
        }
        
        if (!$tenantSlug && $request->getHost() === config('subdomain.main_domain')) {
            return $next($request);
        }
        
        if ($tenantSlug) {
            // Find tenant by slug
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            
            if ($tenant) {
                // Set tenant in app container for use throughout the request
                app()->instance('subdomain_tenant', $tenant);
                
                // Add tenant info to request for easy access
                $request->merge([
                    'tenant_slug' => $tenantSlug,
                    'subdomain_tenant' => $tenant
                ]);
                
                // Add headers for debugging
                if (config('app.debug')) {
                    $request->headers->set('X-Resolved-Tenant-Id', $tenant->id);
                    $request->headers->set('X-Resolved-Tenant-Slug', $tenant->slug);
                }
            } else {
                // Tenant not found - you might want to redirect to a "not found" page
                // or handle this case based on your business logic
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => 'Tenant not found',
                        'slug' => $tenantSlug
                    ], 404);
                }
                
                // For non-API requests, you could redirect to main domain or show 404
                // For now, we'll continue and let the application handle it
                $request->merge([
                    'tenant_slug' => $tenantSlug,
                    'subdomain_tenant' => null,
                    'tenant_not_found' => true
                ]);
            }
        }
        
        $response = $next($request);
        
        // Add CORS headers for subdomain requests
        if ($tenantSlug) {
            $response->headers->add([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            ]);
        }
        
        return $response;
    }
    
    /**
     * Extract tenant slug from the request.
     * 
     * @param Request $request
     * @return string|null
     */
    private function extractTenantSlug(Request $request): ?string
    {
        // Method 1: Laravel route domain parameter (when using Route::domain())
        if ($request->route() && $request->route()->parameter('subdomain')) {
            return $request->route()->parameter('subdomain');
        }
        
        // Method 2: Check for custom headers set by nginx
        if ($request->hasHeader('X-Tenant-Slug')) {
            return $request->header('X-Tenant-Slug');
        }
        
        if ($request->hasHeader('X-Subdomain')) {
            return $request->header('X-Subdomain');
        }
        
        // Method 3: Parse from Host header
        $host = $request->getHost();
        
        // Match pattern: {slug}.{main_domain}
        $mainDomain = config('subdomain.main_domain');
        $escapedDomain = preg_quote($mainDomain, '/');
        if (preg_match('/^([a-z0-9-]+)\.' . $escapedDomain . '$/', $host, $matches)) {
            return $matches[1];
        }
        
        // Match pattern for production: {slug}.yourdomain.com
        if (preg_match('/^([a-z0-9-]+)\.([^.]+)\.([^.]+)$/', $host, $matches)) {
            // Only if it's not 'www' subdomain
            if ($matches[1] !== 'www') {
                return $matches[1];
            }
        }
        
        return null;
    }
}