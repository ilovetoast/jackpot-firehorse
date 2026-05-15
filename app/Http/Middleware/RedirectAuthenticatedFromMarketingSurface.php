<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logged-in users hitting public marketing pages are sent to the gateway (Sentry-style),
 * unless they explicitly opted in with ?marketing_site=1 (sets a session flag; URL is cleaned)
 * or already have that session flag from a prior opt-in (e.g. footer link from the app).
 *
 * @see \App\Http\Middleware\ForgetMarketingSiteBypassForApp
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

        if (! Auth::check()) {
            $request->session()->forget(self::SESSION_KEY);
            if ($request->query(self::BYPASS_QUERY_KEY) === self::BYPASS_QUERY_VALUE) {
                $query = collect($request->query())->except(self::BYPASS_QUERY_KEY)->all();
                $path = $request->path();
                $target = ($path === '' || $path === '/') ? '/' : '/'.$path;
                if ($query !== []) {
                    $target .= '?'.http_build_query($query);
                }

                return redirect()->to($target);
            }

            return $next($request);
        }

        if ($request->query(self::BYPASS_QUERY_KEY) === self::BYPASS_QUERY_VALUE) {
            $request->session()->put(self::SESSION_KEY, true);

            $query = collect($request->query())->except(self::BYPASS_QUERY_KEY)->all();
            $path = $request->path();
            $target = ($path === '' || $path === '/') ? '/' : '/'.$path;
            if ($query !== []) {
                $target .= '?'.http_build_query($query);
            }

            return redirect()->to($target);
        }

        if ($request->session()->get(self::SESSION_KEY)) {
            return $next($request);
        }

        return redirect()->route('gateway');
    }
}
