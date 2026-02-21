<?php

namespace App\Http\Middleware;

use App\Services\CloudFrontSignedCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCloudFrontSignedCookies
{
    public function __construct(
        protected CloudFrontSignedCookieService $cookieService
    ) {}

    /**
     * Handle an incoming request.
     *
     * Tenant-scoped: cookies allow access only to https://{cdn}/tenants/{tenant_uuid}/*.
     * Regenerates when tenant changes, cookies missing, or near expiry.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Local environment: skip entirely
        if (app()->environment('local')) {
            return $next($request);
        }

        $response = $next($request);

        // Only set cookies for authenticated users
        if (!$request->user()) {
            return $response;
        }

        // Resolve active tenant (same mechanism as controllers — app('tenant') from ResolveTenant)
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (!$tenant) {
            return $response;
        }

        // Skip if CloudFront not configured
        if (empty(config('cloudfront.domain')) || empty(config('cloudfront.key_pair_id'))) {
            return $response;
        }

        if ($this->shouldRegenerateCookies($request, $tenant)) {
            $this->attachCookiesToResponse($response, $tenant);
        }

        return $response;
    }

    /**
     * Determine if we need to regenerate signed cookies.
     */
    protected function shouldRegenerateCookies(Request $request, $tenant): bool
    {
        $currentTenantUuid = $tenant->uuid;
        $previousTenantUuid = session('cdn_tenant_uuid');

        // No cookies present
        if (!$request->cookie('CloudFront-Policy')) {
            return true;
        }

        // Tenant switched — must regenerate
        if ($previousTenantUuid !== $currentTenantUuid) {
            return true;
        }

        // Near expiry
        $threshold = config('cloudfront.refresh_threshold', 600);
        if ($threshold <= 0) {
            return false;
        }

        $expiresAt = $this->getPolicyExpiry($request->cookie('CloudFront-Policy'));
        if ($expiresAt === null) {
            return true;
        }

        return $expiresAt < (time() + $threshold);
    }

    /**
     * Extract expiry timestamp from CloudFront-Policy cookie (URL-safe base64 JSON).
     */
    protected function getPolicyExpiry(?string $policy): ?int
    {
        if (!$policy) {
            return null;
        }

        $decoded = str_replace(['-', '_', '~'], ['+', '=', '/'], $policy);
        $json = base64_decode($decoded, true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        $epoch = $data['Statement'][0]['Condition']['DateLessThan']['AWS:EpochTime'] ?? null;

        return is_numeric($epoch) ? (int) $epoch : null;
    }

    /**
     * Attach tenant-scoped CloudFront signed cookies to the response.
     */
    protected function attachCookiesToResponse(Response $response, $tenant): void
    {
        try {
            $cookies = $this->cookieService->generateForTenant($tenant);
        } catch (\Throwable $e) {
            report($e);
            return;
        }

        $expirySeconds = $this->cookieService->getExpirySeconds();
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');
        $path = '/';

        foreach ($cookies as $name => $value) {
            // raw: true — CloudFront must receive the exact policy string; Laravel encryption would break CDN validation
            $cookie = cookie(
                name: $name,
                value: $value,
                minutes: (int) ceil($expirySeconds / 60),
                path: $path,
                domain: $domain,
                secure: true,
                httpOnly: true,
                raw: true,
                sameSite: 'none'
            );
            $response->headers->setCookie($cookie);
        }

        session(['cdn_tenant_uuid' => $tenant->uuid]);
    }
}
