<?php

namespace App\Http\Middleware;

use App\Support\TenantMailBranding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantMailBranding
{
    /**
     * Apply staging tenant From name / fixed From address when the current tenant is resolved.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (TenantMailBranding::enabled() && app()->bound('tenant')) {
            TenantMailBranding::apply(app('tenant'));
        }

        return $next($request);
    }
}
