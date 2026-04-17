<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGenerativeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if ($tenant && ($tenant->settings['generative_enabled'] ?? true) === false) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'The generative editor is disabled for this company.',
                ], 403);
            }

            abort(403, 'The generative editor is disabled for this company.');
        }

        return $next($request);
    }
}
