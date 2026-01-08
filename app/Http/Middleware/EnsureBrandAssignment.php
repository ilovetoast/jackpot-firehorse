<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBrandAssignment
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app('tenant');

        // Skip if no tenant or user
        if (!$tenant || !$user) {
            return $next($request);
        }

        // Check if user belongs to tenant
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return $next($request); // Let other middleware handle tenant access
        }

        // Check tenant-level role
        $tenantRole = $user->getRoleForTenant($tenant);

        // Admin/Owner at tenant level have access even without explicit brand assignment
        // But we still want to track their brand assignments for consistency
        if (in_array($tenantRole, ['admin', 'owner'])) {
            return $next($request);
        }

        // Check if user has at least one brand assignment in this tenant
        $hasBrandAssignment = $user->brands()
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (!$hasBrandAssignment) {
            // Redirect to error page
            return redirect()->route('errors.no-brand-assignment');
        }

        return $next($request);
    }
}
