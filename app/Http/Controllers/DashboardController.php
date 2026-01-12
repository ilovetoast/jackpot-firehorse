<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
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
        
        // Get plan limits for storage
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxStorageMB = $planLimits['max_storage_mb'] ?? null;
        
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
        
        // Downloads: Total download completed events (all time)
        $downloads = AssetEvent::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('event_type', EventType::ASSET_DOWNLOAD_COMPLETED)
            ->count();
            
        // Downloads up to end of last month (for comparison)
        $downloadsLastMonth = AssetEvent::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('event_type', EventType::ASSET_DOWNLOAD_COMPLETED)
            ->where('created_at', '<=', $endOfLastMonth)
            ->count();
            
        // Downloads this month (for percentage calculation)
        $downloadsThisMonth = AssetEvent::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('event_type', EventType::ASSET_DOWNLOAD_COMPLETED)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();
            
        // Downloads last month period (for percentage calculation)
        $downloadsLastMonthPeriod = AssetEvent::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('event_type', EventType::ASSET_DOWNLOAD_COMPLETED)
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();
            
        // Calculate percentage change for downloads (this month vs last month)
        $downloadsChange = $downloadsLastMonthPeriod > 0
            ? round((($downloadsThisMonth - $downloadsLastMonthPeriod) / $downloadsLastMonthPeriod) * 100, 2)
            : ($downloadsThisMonth > 0 ? 100 : 0);
        
        return Inertia::render('Dashboard', [
            'tenant' => $tenant,
            'brand' => $brand,
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
                'downloads' => [
                    'value' => $downloads,
                    'change' => $downloadsChange,
                    'is_positive' => $downloadsChange >= 0,
                ],
            ],
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
