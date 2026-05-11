<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brand setup (cinematic onboarding) and brand guidelines portal/builder are company-admin surfaces.
 * Viewers, uploaders, and brand managers who are not tenant owner/admin/agency_admin cannot access these routes.
 */
class EnsureTenantAdminForBrandWorkspaceSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $user || ! $tenant) {
            return $next($request);
        }

        if ($user->isTenantOwnerAdminOrAgencyAdmin($tenant)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Company admin access required.'], 403);
        }

        return redirect()->route('overview')
            ->with('warning', 'Brand setup and guidelines are available to company owners and admins only.');
    }
}
