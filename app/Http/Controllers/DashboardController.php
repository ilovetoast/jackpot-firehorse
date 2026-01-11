<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        
        // Total Assets: Count completed assets for this brand
        $totalAssets = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('status', AssetStatus::COMPLETED)
            ->count();
            
        // Total Assets last month
        $totalAssetsLastMonth = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('status', AssetStatus::COMPLETED)
            ->where('created_at', '<=', $endOfLastMonth)
            ->count();
            
        // Calculate percentage change for total assets
        $totalAssetsChange = $totalAssetsLastMonth > 0
            ? round((($totalAssets - $totalAssetsLastMonth) / $totalAssetsLastMonth) * 100, 2)
            : ($totalAssets > 0 ? 100 : 0);
        
        // Storage Size: Sum of size_bytes for completed assets (in MB)
        $storageBytes = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('status', AssetStatus::COMPLETED)
            ->sum('size_bytes');
        $storageMB = round($storageBytes / 1024 / 1024, 2);
        
        // Storage Size last month
        $storageBytesLastMonth = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('status', AssetStatus::COMPLETED)
            ->where('created_at', '<=', $endOfLastMonth)
            ->sum('size_bytes');
        $storageMBLastMonth = round($storageBytesLastMonth / 1024 / 1024, 2);
        
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
}
