<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Any navigation under /app/* drops the marketing-site bypass so the next visit to
 * public marketing routes again redirects authenticated users to the gateway.
 */
class ForgetMarketingSiteBypassForApp
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('app/*')) {
            $request->session()->forget(RedirectAuthenticatedFromMarketingSurface::SESSION_KEY);
        }

        return $next($request);
    }
}
