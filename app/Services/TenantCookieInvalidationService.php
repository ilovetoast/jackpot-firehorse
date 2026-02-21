<?php

namespace App\Services;

use App\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helper for invalidating CloudFront signed cookies on tenant switch.
 *
 * Ensures no stale cookie remains when tenant context changes.
 */
class TenantCookieInvalidationService
{
    protected const COOKIE_NAMES = ['CloudFront-Policy', 'CloudFront-Signature', 'CloudFront-Key-Pair-Id'];

    /**
     * Clear CloudFront cookies from response for the given tenant path.
     */
    public function clearCookiesForTenant(Response $response, ?string $tenantUuid = null): void
    {
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');
        $path = $tenantUuid ? '/tenants/' . $tenantUuid . '/' : '/';

        foreach (self::COOKIE_NAMES as $name) {
            $response->headers->clearCookie($name, $path, $domain);
        }
    }

    /**
     * Clear cookies for a tenant (convenience when tenant model available).
     */
    public function clearCookiesForTenantModel(Response $response, ?Tenant $tenant): void
    {
        $this->clearCookiesForTenant($response, $tenant?->uuid);
    }
}
