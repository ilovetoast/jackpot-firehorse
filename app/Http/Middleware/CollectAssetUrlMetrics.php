<?php

namespace App\Http\Middleware;

use App\Services\AssetUrlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectAssetUrlMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('asset_url.metrics_enabled')) {
            return;
        }

        if (! app()->resolved(AssetUrlService::class)) {
            return;
        }

        /** @var AssetUrlService $service */
        $service = app(AssetUrlService::class);
        $metrics = $service->getMetrics();

        if (($metrics['calls'] ?? 0) === 0) {
            return;
        }

        app()->instance('asset_url_request_metrics', $metrics);

        // Keep log volume low by limiting debug output to the performance admin routes.
        if (method_exists($request, 'routeIs') && $request->routeIs('admin.performance.*')) {
            \Log::debug('AssetUrlService Metrics', $metrics);
        }

        $service->resetMetrics();
    }
}

