<?php

namespace App\Http\Middleware;

use App\Services\CloudFrontSignedCookieService;
use App\Services\TenantCookieInvalidationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCloudFrontSignedCookies
{
    public function __construct(
        protected CloudFrontSignedCookieService $cookieService,
        protected TenantCookieInvalidationService $invalidationService
    ) {}

    /**
     * Max tenant UUIDs for admin multi-tenant cookies (avoids browser cookie limits).
     */
    protected const ADMIN_TENANT_COOKIE_LIMIT = 50;

    /**
     * Handle an incoming request.
     *
     * Tenant-scoped: cookies allow access only to https://{cdn}/tenants/{tenant_uuid}/*.
     * Regenerates when tenant changes, cookies missing, or near expiry.
     * Never issues cookies for PUBLIC_COLLECTION or PUBLIC_DOWNLOAD contexts.
     *
     * Admin multi-tenant: site_admin/site_owner on /app/admin/* with admin_tenants from controller
     * receives multiple scoped cookies (one per tenant). No wildcard.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Local environment: skip entirely
        if (app()->environment('local')) {
            return $next($request);
        }

        $response = $next($request);

        // Never issue cookies for public download or public collection (they use signed URLs)
        if ($this->isPublicContext($request)) {
            return $response;
        }

        // Only set cookies for authenticated users
        if (!$request->user()) {
            return $response;
        }

        // Skip if CloudFront not configured
        if (empty(config('cloudfront.domain')) || empty(config('cloudfront.key_pair_id'))) {
            return $response;
        }

        // Admin multi-tenant mode: site_admin/site_owner on /app/admin/* with admin_tenants from controller
        $adminTenants = $request->attributes->get('admin_tenants');
        if ($this->isAdminMultiTenantContext($request, $adminTenants)) {
            try {
                $this->attachAdminMultiTenantCookies($response, $adminTenants, $request);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[CDN] Admin multi-tenant cookies failed', [
                    'error' => $e->getMessage(),
                    'tenant_count' => is_array($adminTenants) ? count($adminTenants) : 0,
                ]);
                report($e);
            }
            return $response;
        }

        // Single-tenant mode (existing behavior)
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (!$tenant) {
            return $response;
        }

        if ($this->shouldRegenerateCookies($request, $tenant)) {
            // Tenant switch: invalidate old cookies before issuing new ones
            if ($this->tenantSwitched($request, $tenant)) {
                $this->invalidateExistingCookies($response, $request);
            }
            $this->attachCookiesToResponse($response, $tenant, $request);
        }

        return $response;
    }

    /**
     * Whether the request is in admin multi-tenant context.
     *
     * Requires: site_admin or site_owner role AND path app/admin/* AND admin_tenants array from controller.
     */
    protected function isAdminMultiTenantContext(Request $request, mixed $adminTenants): bool
    {
        if (!is_array($adminTenants) || empty($adminTenants)) {
            return false;
        }

        $user = $request->user();
        if (!$user) {
            return false;
        }

        $siteRoles = $user->getSiteRoles();
        $isSystemAdmin = $user->id === 1
            || in_array('site_admin', $siteRoles, true)
            || in_array('site_owner', $siteRoles, true);

        if (!$isSystemAdmin) {
            return false;
        }

        return $request->is('app/admin/*');
    }

    /**
     * Attach CloudFront signed cookies for each tenant UUID (admin multi-tenant mode).
     *
     * Each cookie set is scoped to /tenants/{uuid}/. No wildcard.
     */
    protected function attachAdminMultiTenantCookies(Response $response, array $tenantUuids, Request $request): void
    {
        $uuids = array_values(array_unique(array_filter($tenantUuids, fn ($u) => is_string($u) && $u !== '')));
        $uuids = array_slice($uuids, 0, self::ADMIN_TENANT_COOKIE_LIMIT);

        $clientIp = config('cloudfront.cookie_restrict_ip', false) ? $request->ip() : null;
        $expirySeconds = $this->cookieService->getExpirySeconds();
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');

        $issued = 0;
        foreach ($uuids as $uuid) {
            try {
                $cookies = $this->cookieService->generateForTenantUuid($uuid, $clientIp);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[CDN] Admin multi-tenant cookie generation failed for tenant', [
                    'tenant_uuid' => $uuid,
                    'error' => $e->getMessage(),
                ]);
                report($e);
                continue;
            }

            $path = '/tenants/' . $uuid . '/';

            foreach ($cookies as $name => $value) {
                $cookie = cookie(
                    name: $name,
                    value: $value,
                    minutes: (int) ceil($expirySeconds / 60),
                    path: $path,
                    domain: $domain,
                    secure: true,
                    httpOnly: true,
                    raw: true,
                    sameSite: 'lax'
                );
                $response->headers->setCookie($cookie);
            }
            $issued++;
        }

        \Illuminate\Support\Facades\Log::info('[CDN] Admin multi-tenant cookies completed', [
            'issued' => $issued,
            'total_uuids' => count($uuids),
        ]);
    }

    /**
     * Whether the request is for a public context (download or collection).
     * No CDN cookie should be issued for these — they use signed URLs.
     */
    protected function isPublicContext(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'd/')  // Public download: /d/{download}
            || str_starts_with($path, 'b/'); // Public collection: /b/{brand}/collections/...
    }

    /**
     * Whether tenant context changed (stale cookie would grant wrong tenant).
     */
    protected function tenantSwitched(Request $request, $tenant): bool
    {
        $previous = session('cdn_tenant_uuid');

        return $previous !== null && $previous !== $tenant->uuid;
    }

    /**
     * Clear existing CloudFront cookies so no stale cookie remains after tenant switch.
     */
    protected function invalidateExistingCookies(Response $response, Request $request): void
    {
        $previousUuid = session('cdn_tenant_uuid');
        $this->invalidationService->clearCookiesForTenant($response, $previousUuid);
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

        // Near expiry: regenerate if < 5 minutes remain (prevents mid-session 403s)
        $threshold = config('cloudfront.refresh_threshold', 300);
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
     *
     * Cookie path is /tenants/{tenant_uuid}/ so the cookie is only sent for that tenant's CDN paths.
     * Prevents cross-tenant access at CDN layer.
     */
    protected function attachCookiesToResponse(Response $response, $tenant, Request $request): void
    {
        try {
            $clientIp = config('cloudfront.cookie_restrict_ip', false) ? $request->ip() : null;
            $cookies = $this->cookieService->generateForTenant($tenant, $clientIp);
        } catch (\Throwable $e) {
            report($e);
            return;
        }

        $expirySeconds = $this->cookieService->getExpirySeconds();
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');
        // Path scoped to tenant: cookie only sent for /tenants/{uuid}/* — prevents cross-tenant bleed
        $path = '/tenants/' . $tenant->uuid . '/';

        $expiresAt = time() + $expirySeconds;

        \Illuminate\Support\Facades\Log::channel('single')->info('[CDN] Signed cookie issued', [
            'tenant_id' => $tenant->id,
            'tenant_uuid' => $tenant->uuid,
            'expires_at' => $expiresAt,
            'expires_at_iso' => date('c', $expiresAt),
        ]);

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
                sameSite: 'lax'
            );
            $response->headers->setCookie($cookie);
        }

        session(['cdn_tenant_uuid' => $tenant->uuid]);
    }
}
