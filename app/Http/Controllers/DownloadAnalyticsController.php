<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Services\DownloadAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * D9 — Download Analytics (Internal)
 *
 * Read-only. Admin/Owner/Manager only. No schema changes. Data from activity_events + downloads + download_asset.
 */
class DownloadAnalyticsController extends Controller
{
    public function __construct(
        protected DownloadAnalyticsService $analytics
    ) {}

    /**
     * GET /app/downloads/{download}/analytics
     * Returns JSON: summary, recent_activity, asset_breakdown.
     */
    public function show(Request $request, Download $download): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $user || ! $tenant) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($download->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Download not found.'], 404);
        }

        if (! $this->canManageDownload($user, $tenant)) {
            return response()->json(['message' => 'You cannot view analytics for this download.'], 403);
        }

        $summary = $this->analytics->summaryForDownload($download);
        $recentActivity = $this->analytics->recentActivityForDownload($download, 10);
        $assetBreakdown = $this->analytics->assetBreakdownForDownload($download);

        return response()->json([
            'summary' => [
                'total_downloads' => $summary['total_downloads'],
                'landing_page_views' => $summary['landing_page_views'] ?? 0,
                'unique_users' => $summary['unique_users'],
                'first_downloaded_at' => $summary['first_downloaded_at']?->toIso8601String(),
                'last_downloaded_at' => $summary['last_downloaded_at']?->toIso8601String(),
                'source_breakdown' => $summary['source_breakdown'],
            ],
            'recent_activity' => array_map(fn ($a) => [
                'at' => $a['at']->toIso8601String(),
                'user' => $a['user'],
                'ip_hash' => $a['ip_hash'],
                'user_agent' => $a['user_agent'],
                'event' => $a['event'],
            ], $recentActivity),
            'asset_breakdown' => $assetBreakdown,
        ]);
    }

    protected function canManageDownload($user, $tenant): bool
    {
        if (! $user) {
            return false;
        }
        if (app()->bound('collection_only') && app('collection_only')) {
            return false;
        }
        $tenantRole = $user->getRoleForTenant($tenant);
        if (in_array(strtolower($tenantRole ?? ''), ['owner', 'admin'])) {
            return true;
        }
        $brand = app('brand');
        if (! $brand) {
            return false;
        }
        $brandRole = $user->getRoleForBrand($brand);
        return in_array(strtolower($brandRole ?? ''), ['brand_manager', 'admin']);
    }
}
