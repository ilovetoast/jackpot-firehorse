<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CloudFrontSignedCookieService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dedicated endpoint to set CloudFront signed cookies for admin multi-tenant views.
 * Used by the admin assets index so the main document response does not include
 * 9 Set-Cookie headers (which can cause 502 with some proxies).
 */
class AdminCdnCookiesController extends Controller
{
    protected const ADMIN_TENANT_COOKIE_LIMIT = 50;

    public function __construct(
        protected CloudFrontSignedCookieService $cookieService
    ) {}

    /**
     * GET /app/admin/cdn-cookies?uuids=uuid1,uuid2,uuid3
     * Sets signed cookies for each tenant UUID and returns 204 No Content.
     */
    public function __invoke(Request $request)
    {
        $this->authorizeAdmin();

        if (empty(config('cloudfront.domain')) || empty(config('cloudfront.key_pair_id'))) {
            return response()->noContent(204);
        }

        $uuidsParam = $request->query('uuids', '');
        $uuids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $uuidsParam)),
            fn ($u) => $u !== ''
        )));
        $uuids = array_slice($uuids, 0, self::ADMIN_TENANT_COOKIE_LIMIT);

        if (empty($uuids)) {
            return response()->noContent(204);
        }

        $response = response()->noContent(204);
        $clientIp = config('cloudfront.cookie_restrict_ip', false) ? $request->ip() : null;
        $expirySeconds = $this->cookieService->getExpirySeconds();
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');

        $issued = 0;
        foreach ($uuids as $uuid) {
            try {
                $cookies = $this->cookieService->generateForTenantUuid($uuid, $clientIp);
            } catch (\Throwable $e) {
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

        return $response;
    }

    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only site owners or site admins can access this endpoint.');
        }
    }
}
