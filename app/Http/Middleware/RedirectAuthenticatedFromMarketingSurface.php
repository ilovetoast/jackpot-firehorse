<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated users hitting public marketing pages are redirected based on session state:
 *
 *   1. Active workspace session (tenant_id + brand_id in session)
 *      → `/app/overview` immediately — skip the gateway entirely.
 *
 *   2. No session but resume cookie still within the 1–4 hour window
 *      → `/gateway`, which reads the cookie, auto-sets the session, and cinematic-enters.
 *      The user never sees a picker; the transition is near-instant.
 *
 *   3. Outside the inactivity window (no session, no valid resume cookie)
 *      → `/gateway` showing the workspace picker so they can choose where to enter.
 *
 * Unauthenticated users always see the marketing page normally.
 * The `?marketing_site=1` bypass and session flag are preserved for marketing/blog links
 * from inside the app that intentionally want to show the public page.
 *
 * @see \App\Support\GatewayResumeCookie  (cookie TTL: 1–4 hours via GATEWAY_RESUME_TTL_MINUTES)
 * @see \App\Http\Middleware\EnsureGatewayEntry  (clears bypass on /app/*)
 */
class RedirectAuthenticatedFromMarketingSurface
{
    public const BYPASS_QUERY_KEY = 'marketing_site';

    public const BYPASS_QUERY_VALUE = '1';

    public const SESSION_KEY = 'marketing_site_bypass';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        // ── Unauthenticated ──────────────────────────────────────────────────
        if (! Auth::check()) {
            $request->session()->forget(self::SESSION_KEY);

            // Strip the bypass param if someone lands here with it while not logged in
            if ($request->query(self::BYPASS_QUERY_KEY) === self::BYPASS_QUERY_VALUE) {
                return redirect()->to($this->stripBypassParam($request));
            }

            return $next($request);
        }

        // ── Authenticated: explicit marketing-site bypass ────────────────────
        // ?marketing_site=1 lets app footers / blog links open the public page intentionally.
        if ($request->query(self::BYPASS_QUERY_KEY) === self::BYPASS_QUERY_VALUE) {
            $request->session()->put(self::SESSION_KEY, true);

            return redirect()->to($this->stripBypassParam($request));
        }

        // Still within the bypass session (set by a prior ?marketing_site=1 hit)
        if ($request->session()->get(self::SESSION_KEY)) {
            return $next($request);
        }

        // ── Authenticated: decide where to send the user ─────────────────────

        // Case 1 — Active workspace session → go straight into the app.
        // EnsureGatewayEntry will validate the session on the /app/* side.
        if (session('tenant_id') && session('brand_id')) {
            return redirect('/app/overview');
        }

        // Case 2 — No session but within the resume window (valid cookie).
        // Send to /gateway; the controller reads the cookie, restores session, and
        // triggers cinematic auto-enter without ever showing the workspace picker.
        //
        // Case 3 — Outside the window (cookie expired or absent).
        // /gateway shows the workspace picker so the user can choose where to enter.
        return redirect()->route('gateway');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stripBypassParam(Request $request): string
    {
        $query = collect($request->query())->except(self::BYPASS_QUERY_KEY)->all();
        $path  = $request->path();
        $target = ($path === '' || $path === '/') ? '/' : '/'.$path;

        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        return $target;
    }
}
