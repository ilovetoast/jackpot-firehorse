<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetMetric;
use App\Models\Download;
use App\Enums\DownloadStatus;
use App\Enums\MetricType;
use App\Services\AssetCompletionService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Display the dashboard with asset statistics.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        
        if (!$tenant || !$brand) {
            abort(403, 'Tenant and brand must be selected.');
        }
        
        // Get current plan name
        $planName = $this->planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));
        $planDisplayName = $planConfig['name'] ?? ucfirst($planName);
        
        // Check if user is owner or admin
        $user = Auth::user();
        $isOwner = $tenant->isOwner($user);
        $userRole = $user->getRoleForTenant($tenant);
        $isAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);
        $canSeePlanBadge = $isOwner || $isAdmin;
        
        // Get plan limits for storage and downloads
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxStorageMB = $planLimits['max_storage_mb'] ?? null;
        $maxDownloadsPerMonth = $planLimits['max_downloads_per_month'] ?? null;
        
        // Get current period (this month)
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        
        // Get previous period (last month)
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();
        
        // Total Assets: Count assets with completed processing pipeline
        // Query by processing state (thumbnail_status + metadata flags), not status
        $totalAssets = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
            ->count();
            
        // Total Assets last month
        $totalAssetsLastMonth = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
            ->where('created_at', '<=', $endOfLastMonth)
            ->count();
            
        // Calculate percentage change for total assets
        $totalAssetsChange = $totalAssetsLastMonth > 0
            ? round((($totalAssets - $totalAssetsLastMonth) / $totalAssetsLastMonth) * 100, 2)
            : ($totalAssets > 0 ? 100 : 0);
        
        // Storage Size: Sum of size_bytes for completed assets (in MB)
        $storageBytes = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
            ->sum('size_bytes');
        $storageMB = round($storageBytes / 1024 / 1024, 2);
        
        // Storage Size last month
        $storageBytesLastMonth = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
            ->where('created_at', '<=', $endOfLastMonth)
            ->sum('size_bytes');
        $storageMBLastMonth = round($storageBytesLastMonth / 1024 / 1024, 2);
        
        // AUDIT: Log query results and sample asset brand_ids for comparison
        $sampleAssets = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
            ->limit(5)
            ->get(['id', 'brand_id']);
        if ($sampleAssets->count() > 0) {
            $sampleAssetBrandIds = $sampleAssets->pluck('brand_id')->unique()->values()->toArray();
            Log::info('[ASSET_QUERY_AUDIT] DashboardController::index() query results', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'total_assets_count' => $totalAssets,
                'sample_asset_brand_ids' => $sampleAssetBrandIds,
                'brand_id_mismatch_count' => $sampleAssets->filter(fn($a) => $a->brand_id != $brand->id)->count(),
                'note' => 'If brand_id_mismatch_count > 0, query brand_id does not match stored asset brand_id',
            ]);
        } else {
            Log::info('[ASSET_QUERY_AUDIT] DashboardController::index() query results (empty)', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'total_assets_count' => 0,
                'note' => 'No assets found - cannot compare brand_ids',
            ]);
        }
        
        // Calculate percentage change for storage
        $storageChange = $storageMBLastMonth > 0
            ? round((($storageMB - $storageMBLastMonth) / $storageMBLastMonth) * 100, 2)
            : ($storageMB > 0 ? 100 : 0);
        
        // Download Links: Count Download model records (download groups/ZIP files) for this brand
        // Only count downloads that have assets from this brand and are ready/completed
        $downloadLinksThisMonth = Download::where('tenant_id', $tenant->id)
            ->where('status', DownloadStatus::READY)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereHas('assets', function ($query) use ($brand) {
                $query->where('brand_id', $brand->id);
            })
            ->count();
            
        // Download Links last month period (for percentage calculation)
        $downloadLinksLastMonthPeriod = Download::where('tenant_id', $tenant->id)
            ->where('status', DownloadStatus::READY)
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->whereHas('assets', function ($query) use ($brand) {
                $query->where('brand_id', $brand->id);
            })
            ->count();
            
        // Calculate percentage change for download links (this month vs last month)
        $downloadLinksChange = $downloadLinksLastMonthPeriod > 0
            ? round((($downloadLinksThisMonth - $downloadLinksLastMonthPeriod) / $downloadLinksLastMonthPeriod) * 100, 2)
            : ($downloadLinksThisMonth > 0 ? 100 : 0);
        
        // Get most viewed assets (top 6) - only visible, non-deleted assets
        $mostViewedAssetIds = AssetMetric::where('asset_metrics.tenant_id', $tenant->id)
            ->where('asset_metrics.brand_id', $brand->id)
            ->where('asset_metrics.metric_type', MetricType::VIEW->value)
            ->join('assets', 'asset_metrics.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->select('assets.id', DB::raw('COUNT(asset_metrics.id) as view_count'))
            ->groupBy('assets.id')
            ->orderByDesc('view_count')
            ->limit(6)
            ->pluck('view_count', 'id');
        
        $mostViewedAssets = collect();
        if ($mostViewedAssetIds->isNotEmpty()) {
            $assets = Asset::whereIn('id', $mostViewedAssetIds->keys())
                ->get()
                ->keyBy('id');
            
            $mostViewedAssets = $mostViewedAssetIds->map(function ($viewCount, $assetId) use ($assets) {
                $asset = $assets->get($assetId);
                if (!$asset) {
                    return null;
                }
                
                // Generate thumbnail URLs (same logic as AssetController)
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                    ? $asset->thumbnail_status->value 
                    : ($asset->thumbnail_status ?? 'pending');
                
                $previewThumbnailUrl = null;
                $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                    $previewThumbnailUrl = route('assets.thumbnail.preview', [
                        'asset' => $asset->id,
                        'style' => 'preview',
                    ]);
                }
                
                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnails = $metadata['thumbnails'] ?? [];
                    if (!empty($thumbnails) && isset($thumbnails['medium'])) {
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => 'medium',
                        ]);
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . $thumbnailVersion;
                        }
                    }
                }
                
                return [
                    'id' => $asset->id,
                    'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                    'original_filename' => $asset->original_filename,
                    'final_thumbnail_url' => $finalThumbnailUrl,
                    'preview_thumbnail_url' => $previewThumbnailUrl,
                    'thumbnail_url' => $finalThumbnailUrl ?? null,
                    'thumbnail_status' => $thumbnailStatus,
                    'view_count' => (int) $viewCount,
                ];
            })->filter()->values();
        }
        
        // Get most downloaded assets (top 6) - only visible, non-deleted assets
        $mostDownloadedAssetIds = AssetMetric::where('asset_metrics.tenant_id', $tenant->id)
            ->where('asset_metrics.brand_id', $brand->id)
            ->where('asset_metrics.metric_type', MetricType::DOWNLOAD->value)
            ->join('assets', 'asset_metrics.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->select('assets.id', DB::raw('COUNT(asset_metrics.id) as download_count'))
            ->groupBy('assets.id')
            ->orderByDesc('download_count')
            ->limit(6)
            ->pluck('download_count', 'id');
        
        $mostDownloadedAssets = collect();
        if ($mostDownloadedAssetIds->isNotEmpty()) {
            $assets = Asset::whereIn('id', $mostDownloadedAssetIds->keys())
                ->get()
                ->keyBy('id');
            
            $mostDownloadedAssets = $mostDownloadedAssetIds->map(function ($downloadCount, $assetId) use ($assets) {
                $asset = $assets->get($assetId);
                if (!$asset) {
                    return null;
                }
                
                // Generate thumbnail URLs (same logic as AssetController)
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                    ? $asset->thumbnail_status->value 
                    : ($asset->thumbnail_status ?? 'pending');
                
                $previewThumbnailUrl = null;
                $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                    $previewThumbnailUrl = route('assets.thumbnail.preview', [
                        'asset' => $asset->id,
                        'style' => 'preview',
                    ]);
                }
                
                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnails = $metadata['thumbnails'] ?? [];
                    if (!empty($thumbnails) && isset($thumbnails['medium'])) {
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => 'medium',
                        ]);
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . $thumbnailVersion;
                        }
                    }
                }
                
                return [
                    'id' => $asset->id,
                    'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                    'original_filename' => $asset->original_filename,
                    'final_thumbnail_url' => $finalThumbnailUrl,
                    'preview_thumbnail_url' => $previewThumbnailUrl,
                    'thumbnail_url' => $finalThumbnailUrl ?? null,
                    'thumbnail_status' => $thumbnailStatus,
                    'download_count' => (int) $downloadCount,
                ];
            })->filter()->values();
        }
        
        return Inertia::render('Dashboard', [
            'tenant' => $tenant,
            'brand' => $brand,
            'plan' => [
                'name' => $planDisplayName,
                'key' => $planName,
                'show_badge' => $canSeePlanBadge,
            ],
            'stats' => [
                'total_assets' => [
                    'value' => $totalAssets,
                    'change' => $totalAssetsChange,
                    'is_positive' => $totalAssetsChange >= 0,
                ],
                'storage_mb' => [
                    'value' => $storageMB,
                    'change' => $storageChange,
                    'is_positive' => $storageChange >= 0,
                    'limit' => $maxStorageMB, // null or number (999999 = unlimited)
                ],
                'download_links' => [
                    'value' => $downloadLinksThisMonth, // Current month download links (Download model)
                    'change' => $downloadLinksChange,
                    'is_positive' => $downloadLinksChange >= 0,
                    'limit' => $maxDownloadsPerMonth, // Monthly download limit
                ],
            ],
            'most_viewed_assets' => $mostViewedAssets,
            'most_downloaded_assets' => $mostDownloadedAssets,
        ]);
    }

    /**
     * Get query for assets with completed processing pipeline.
     * 
     * Completion criteria (matches AssetCompletionService):
     * - thumbnail_status === COMPLETED
     * - metadata['ai_tagging_completed'] === true
     * - metadata['metadata_extracted'] === true
     * - metadata['preview_generated'] === true (optional - if key exists, must be true)
     * 
     * Asset.status represents VISIBILITY only, so we query VISIBLE assets
     * and filter by processing state.
     */
    protected function getCompletedAssetsQuery(int $tenantId, int $brandId)
    {
        return Asset::where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('status', AssetStatus::VISIBLE) // Only visible assets
            ->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->where('metadata->ai_tagging_completed', true)
            ->where('metadata->metadata_extracted', true)
            ->where(function ($query) {
                // Preview generated is optional - if key exists, must be true; if doesn't exist, allow
                $query->where('metadata->preview_generated', true)
                      ->orWhereNull('metadata->preview_generated');
            })
            ->whereNull('deleted_at');
    }
}
