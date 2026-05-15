<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When {@see config('app.block_search_engine_crawling')} is true, tells crawlers not to index
 * any URL on this host (HTML, JSON, downloads, etc.) via the X-Robots-Tag HTTP header.
 */
class BlockSearchEngineCrawling
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('app.block_search_engine_crawling')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
