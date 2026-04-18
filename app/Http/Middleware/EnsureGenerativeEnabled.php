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

        // Master AI kill switch — blocks Studio (generative) even when the feature-
        // specific `generative_enabled` flag is true. Defaults to true when absent.
        if ($tenant && ($tenant->settings['ai_enabled'] ?? true) === false) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'AI features are disabled for this company.',
                ], 403);
            }

            abort(403, 'AI features are disabled for this company.');
        }

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
