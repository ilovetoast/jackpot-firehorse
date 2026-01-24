<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetMetric;
use App\Models\Download;
use App\Enums\DownloadStatus;
use App\Enums\MetricType;
use App\Services\AiUsageService;
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
        protected PlanService $planService,
        protected AiUsageService $aiUsageService
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
        
        // Get AI usage data (tenant-scoped, shared across all brands)
        // Only include if user has permission to view AI usage
        $aiUsageData = null;
        if ($user->hasPermissionForTenant($tenant, 'ai.usage.view')) {
            $usageStatus = $this->aiUsageService->getUsageStatus($tenant);
            $aiUsageData = [
                'tagging' => [
                    'usage' => $usageStatus['tagging']['usage'] ?? 0,
                    'cap' => $usageStatus['tagging']['cap'] ?? 0,
                    'is_unlimited' => $usageStatus['tagging']['is_unlimited'] ?? false,
                    'is_disabled' => $usageStatus['tagging']['is_disabled'] ?? false,
                    'is_exceeded' => $usageStatus['tagging']['is_exceeded'] ?? false,
                    'remaining' => $usageStatus['tagging']['remaining'] ?? null,
                    'percentage' => $usageStatus['tagging']['percentage'] ?? 0,
                ],
                'suggestions' => [
                    'usage' => $usageStatus['suggestions']['usage'] ?? 0,
                    'cap' => $usageStatus['suggestions']['cap'] ?? 0,
                    'is_unlimited' => $usageStatus['suggestions']['is_unlimited'] ?? false,
                    'is_disabled' => $usageStatus['suggestions']['is_disabled'] ?? false,
                    'is_exceeded' => $usageStatus['suggestions']['is_exceeded'] ?? false,
                    'remaining' => $usageStatus['suggestions']['remaining'] ?? null,
                    'percentage' => $usageStatus['suggestions']['percentage'] ?? 0,
                ],
            ];
        }
        
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
        
        // Get recent company activity (last 5) - only if user has permission
        $recentActivity = null;
        if ($user->hasPermissionForTenant($tenant, 'activity_logs.view')) {
            $activityEvents = ActivityEvent::where('tenant_id', $tenant->id)
                ->where('event_type', '!=', EventType::AI_SYSTEM_INSIGHT) // Exclude system-level AI insights
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->with(['brand', 'subject']) // Load relationships (excluding actor for polymorphic issues)
                ->get();
            
            $recentActivity = $activityEvents->map(function ($event) {
                // Format event type display name
                $eventTypeLabel = $this->formatEventTypeLabel($event->event_type);
                
                // Get actor name
                $actorName = 'System';
                if ($event->actor_type === 'user' && $event->actor) {
                    $actorName = $event->actor->name;
                } elseif (!empty($event->metadata['actor_name'])) {
                    $actorName = $event->metadata['actor_name'];
                }
                
                // Get subject name with better fallbacks
                $subjectName = 'Unknown';
                if ($event->subject) {
                    if (method_exists($event->subject, 'title') && !empty($event->subject->title)) {
                        $subjectName = $event->subject->title;
                    } elseif (method_exists($event->subject, 'original_filename') && !empty($event->subject->original_filename)) {
                        $subjectName = $event->subject->original_filename;
                    } elseif (method_exists($event->subject, 'name') && !empty($event->subject->name)) {
                        $subjectName = $event->subject->name;
                    } elseif (method_exists($event->subject, 'id')) {
                        // For assets, create a generic name with ID
                        if ($event->subject_type === 'App\\Models\\Asset') {
                            $subjectName = 'Asset #' . substr($event->subject->id, 0, 8);
                        } else {
                            $subjectName = 'Item #' . substr($event->subject->id, 0, 8);
                        }
                    }
                } elseif (!empty($event->metadata['subject_name'])) {
                    $subjectName = $event->metadata['subject_name'];
                } elseif (!empty($event->metadata['asset_title'])) {
                    $subjectName = $event->metadata['asset_title'];
                } elseif (!empty($event->metadata['asset_filename'])) {
                    $subjectName = $event->metadata['asset_filename'];
                }
                
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'event_type_label' => $eventTypeLabel,
                    'description' => $event->metadata['description'] ?? null,
                    'actor' => [
                        'type' => $event->actor_type,
                        'name' => $actorName,
                    ],
                    'subject' => [
                        'type' => $event->subject_type,
                        'name' => $subjectName,
                        'id' => $event->subject_id,
                    ],
                    'brand' => $event->brand ? [
                        'id' => $event->brand->id,
                        'name' => $event->brand->name,
                    ] : null,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at->toISOString(),
                    'created_at_human' => $event->created_at->diffForHumans(),
                ];
            });
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
            'ai_usage' => $aiUsageData, // Tenant-scoped AI usage (shared across all brands)
            'recent_activity' => $recentActivity, // Recent company activity (permission-gated)
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
    
    /**
     * Format event type constant into a readable display name.
     * 
     * @param string $eventType
     * @return string
     */
    protected function formatEventTypeLabel(string $eventType): string
    {
        // Convert dot notation to readable format
        // e.g., "asset.uploaded" -> "Asset Uploaded"
        $parts = explode('.', $eventType);
        $formatted = array_map(function ($part) {
            $formatted = ucfirst(str_replace('_', ' ', $part));
            // Handle common abbreviations
            $formatted = str_replace('Ai ', 'AI ', $formatted);
            return $formatted;
        }, $parts);
        
        return implode(' ', $formatted);
    }
}
