<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5: Stamp every HTTP request with a stable correlation id so we
 * can stitch together preflight → initiate-batch → finalize log lines
 * for a single user upload attempt during incident response.
 *
 *   - Reads incoming `X-Request-Id` header if a downstream gateway
 *     already set one (CloudFront, ALB, Cloudflare, etc.).
 *   - Otherwise generates a UUIDv4.
 *   - Stores on the request as `request_id` and on a static slot for
 *     code (queue jobs, log helpers) that doesn't have a Request handy.
 *   - Echoes the id back as `X-Request-Id` so frontend/Sentry traces
 *     can be cross-referenced with backend audit logs.
 */
class AssignRequestId
{
    public static ?string $current = null;

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) ($request->headers->get('X-Request-Id') ?? '');
        $rid = $this->isValidRequestId($incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $rid);
        static::$current = $rid;

        try {
            $response = $next($request);
        } finally {
            // Clear after the response so async workers don't accidentally
            // adopt a stale id from a previous request on the same worker.
            static::$current = null;
        }

        if (method_exists($response, 'headers')) {
            $response->headers->set('X-Request-Id', $rid);
        }

        return $response;
    }

    /**
     * Loose validation — incoming ids may be UUIDs, AWS request ids, or
     * hex-ish strings from gateways. We only reject anything dangerous /
     * unbounded.
     */
    protected function isValidRequestId(string $value): bool
    {
        if ($value === '' || strlen($value) > 128) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
    }
}
