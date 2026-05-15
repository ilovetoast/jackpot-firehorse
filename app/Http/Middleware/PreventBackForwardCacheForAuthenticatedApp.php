<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Disables browser bfcache (back/forward cache) for authenticated /app routes.
 *
 * Why this exists
 * ---------------
 * Tenant context lives in the server session ({@see CompanyController::switch}).
 * After a workspace switch, browser-Back can restore an /app/... page from
 * bfcache — DOM and React state restored from memory, *no* server hit. That
 * restored DOM was rendered for the previous tenant, but every subsequent XHR
 * fires against the new tenant's session cookie. Result: half-stuck UI ("the
 * back button froze"), 403/404 noise, mismatched data.
 *
 * `Cache-Control: no-store` is the strongest signal Chrome/Safari honour to
 * skip bfcache entirely (see https://web.dev/articles/bfcache#cache-control-no-store).
 * Back-nav now triggers a fresh server render, which reads the *current*
 * session/tenant — so the rendered page always matches reality.
 *
 * Cost: marginally slower Back navigation on /app pages (one round-trip vs
 * instant bfcache restore). For an authenticated SaaS where state staleness
 * is a correctness bug, that trade is correct.
 *
 * Excluded responses
 * ------------------
 * Streamed and file-download responses (signed URLs, ZIPs, exports) are left
 * untouched — they have their own cache semantics, support range requests, and
 * are not subject to bfcache anyway.
 *
 * Belt-and-braces: see also resources/js/app.jsx for a `pageshow` handler that
 * forces a reload on `event.persisted`, in case a browser/extension still
 * restores from bfcache despite this header.
 */
class PreventBackForwardCacheForAuthenticatedApp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        // `private`: never store in shared caches (CDN/proxy).
        // `no-store`: don't store *anywhere*, including disk/bfcache.
        // `no-cache, must-revalidate, max-age=0`: belt-and-braces for older intermediaries
        // that may not honour `no-store` alone.
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0, private',
            true,
        );
        // Some intermediaries still respect Pragma; harmless to set.
        $response->headers->set('Pragma', 'no-cache', true);

        return $response;
    }
}
