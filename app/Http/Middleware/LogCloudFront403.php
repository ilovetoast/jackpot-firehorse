<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Observability: log when a 403 response is returned and the request host
 * matches the CloudFront domain (e.g. signed URL or CDN path rejected).
 * Registered for admin routes only. Does not modify public cookie-based flow.
 */
class LogCloudFront403
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('app/admin*')) {
            return $response;
        }

        if ($response->getStatusCode() !== 403) {
            return $response;
        }

        $domain = config('cloudfront.domain');
        if ($domain === '' || $request->getHost() !== $domain) {
            return $response;
        }

        Log::warning('[CloudFront403] 403 response for request matching CloudFront domain', [
            'url' => $request->fullUrl(),
            'expires_param' => $request->query('Expires'),
            'current_timestamp' => now()->timestamp,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'request_id' => $request->header('X-Request-ID') ?? $request->attributes->get('request_id'),
        ]);

        return $response;
    }
}
