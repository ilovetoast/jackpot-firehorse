<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientPerformanceMetric;
use App\Models\PerformanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Observability v1: Performance monitoring dashboard and client metrics ingestion.
 */
class PerformanceController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $isEngineering = in_array('site_engineering', $siteRoles);
        if (!$isSiteOwner && !$isSiteAdmin && !$isEngineering) {
            abort(403, 'Only site administrators can access performance data.');
        }
    }

    /**
     * GET /app/admin/performance - Dashboard.
     */
    public function index(): Response
    {
        $this->authorizeAdmin();

        $metrics = $this->getMetrics();
        $assetUrlMetrics = null;
        if (config('asset_url.metrics_enabled')) {
            $assetUrlMetrics = app()->bound('asset_url_request_metrics')
                ? app('asset_url_request_metrics')
                : app(\App\Services\AssetUrlService::class)->getMetrics();

            if (($assetUrlMetrics['calls'] ?? 0) === 0) {
                $assetUrlMetrics = null;
            }
        }

        return Inertia::render('Admin/Performance/Index', [
            'metrics' => $metrics,
            'asset_url_metrics' => $assetUrlMetrics,
        ]);
    }

    /**
     * GET /app/admin/performance/api - API for polling.
     */
    public function api(): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json($this->getMetrics());
    }

    /**
     * POST /app/admin/performance/client-metric - Ingest client-side metrics.
     */
    public function clientMetric(Request $request): JsonResponse
    {
        if (!config('performance.client_metrics_enabled', false)) {
            return response()->json(['ok' => false, 'reason' => 'disabled'], 403);
        }

        $request->validate([
            'url' => 'required|string|max:2048',
            'path' => 'nullable|string|max:512',
            'ttfb_ms' => 'nullable|integer|min:0',
            'dom_content_loaded_ms' => 'nullable|integer|min:0',
            'load_event_ms' => 'nullable|integer|min:0',
            'total_load_ms' => 'nullable|integer|min:0',
            'avg_image_load_ms' => 'nullable|integer|min:0',
            'image_count' => 'nullable|integer|min:0',
        ]);

        ClientPerformanceMetric::create([
            'url' => $request->input('url'),
            'path' => $request->input('path'),
            'ttfb_ms' => $request->input('ttfb_ms'),
            'dom_content_loaded_ms' => $request->input('dom_content_loaded_ms'),
            'load_event_ms' => $request->input('load_event_ms'),
            'total_load_ms' => $request->input('total_load_ms'),
            'avg_image_load_ms' => $request->input('avg_image_load_ms'),
            'image_count' => $request->input('image_count'),
            'user_id' => $request->user()?->id,
            'session_id' => $request->session()->getId(),
        ]);

        return response()->json(['ok' => true]);
    }

    protected function getMetrics(): array
    {
        $period = now()->subHours(24);

        $serverLogs = [];
        if (class_exists(PerformanceLog::class) && Schema::hasTable('performance_logs')) {
            $serverLogs = [
                'avg_duration_ms' => (int) PerformanceLog::where('created_at', '>=', $period)
                    ->avg('duration_ms'),
                'slowest_routes' => PerformanceLog::where('created_at', '>=', $period)
                    ->select('url', 'method', DB::raw('AVG(duration_ms) as avg_ms'), DB::raw('COUNT(*) as count'))
                    ->groupBy('url', 'method')
                    ->orderByDesc('avg_ms')
                    ->limit(10)
                    ->get()
                    ->map(fn ($r) => [
                        'url' => $r->url,
                        'method' => $r->method,
                        'avg_ms' => (int) $r->avg_ms,
                        'count' => $r->count,
                    ]),
                'p95_duration_ms' => $this->percentile(PerformanceLog::class, 'duration_ms', 95, $period),
                'total_slow_requests' => PerformanceLog::where('created_at', '>=', $period)->count(),
            ];
        }

        $clientMetrics = [];
        if (class_exists(ClientPerformanceMetric::class) && Schema::hasTable('client_performance_metrics')) {
            $clientMetrics = [
                'avg_ttfb_ms' => (int) ClientPerformanceMetric::where('created_at', '>=', $period)
                    ->avg('ttfb_ms'),
                'avg_load_ms' => (int) ClientPerformanceMetric::where('created_at', '>=', $period)
                    ->avg('total_load_ms'),
                'avg_image_load_ms' => (int) ClientPerformanceMetric::where('created_at', '>=', $period)
                    ->whereNotNull('avg_image_load_ms')
                    ->avg('avg_image_load_ms'),
                'slowest_pages' => ClientPerformanceMetric::where('created_at', '>=', $period)
                    ->whereNotNull('total_load_ms')
                    ->select(DB::raw('COALESCE(path, url) as route'), DB::raw('AVG(total_load_ms) as avg_ms'), DB::raw('COUNT(*) as count'))
                    ->groupBy(DB::raw('COALESCE(path, url)'))
                    ->orderByDesc('avg_ms')
                    ->limit(10)
                    ->get()
                    ->map(fn ($r) => [
                        'path' => $r->route ?? '—',
                        'avg_ms' => (int) $r->avg_ms,
                        'count' => $r->count,
                    ]),
            ];
        }

        return [
            'server' => $serverLogs,
            'client' => $clientMetrics,
            'period_hours' => 24,
            'config' => [
                'enabled' => config('performance.enabled', false),
                'persist_slow_logs' => config('performance.persist_slow_logs', false),
                'persist_all_requests' => config('performance.persist_all_requests', false),
                'client_metrics_enabled' => config('performance.client_metrics_enabled', false),
                'slow_threshold_ms' => config('performance.slow_threshold_ms', 1000),
                'asset_url_metrics_enabled' => config('asset_url.metrics_enabled', false),
            ],
        ];
    }

    protected function percentile(string $modelClass, string $column, int $p, $since): ?int
    {
        $count = $modelClass::where('created_at', '>=', $since)->count();
        if ($count < 2) {
            return null;
        }
        $index = (int) ceil($count * ($p / 100)) - 1;
        $value = $modelClass::where('created_at', '>=', $since)
            ->orderBy($column)
            ->skip($index)
            ->value($column);
        return $value !== null ? (int) $value : null;
    }
}
