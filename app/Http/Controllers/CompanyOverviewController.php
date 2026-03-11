<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\DownloadStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Download;
use App\Services\AiUsageService;
use App\Services\BrandService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CompanyOverviewController extends Controller
{
    public function __construct(
        protected PlanService $planService,
        protected AiUsageService $aiUsageService,
        protected BrandService $brandService
    ) {
    }

    /**
     * Display company overview with metrics across all brands.
     * GET /app
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $activeBrand = app('brand');
        $user = Auth::user();

        if (! $tenant) {
            abort(403, 'Tenant must be selected.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'company.view')) {
            abort(403, 'You do not have permission to view the company page.');
        }

        $planName = $this->planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));
        $planDisplayName = $planConfig['name'] ?? ucfirst($planName);
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxBrands = $planLimits['max_brands'] ?? PHP_INT_MAX;

        $tenantRole = $user->getRoleForTenant($tenant);
        $isOwnerOrAdmin = in_array(strtolower($tenantRole ?? ''), ['owner', 'admin']);

        // Get brands: owner/admin see all; others see only brands they have access to
        $brandsQuery = $tenant->brands()->orderBy('is_default', 'desc')->orderBy('name');
        if (! $isOwnerOrAdmin) {
            $brandIds = $user->brands()->where('tenant_id', $tenant->id)->pluck('brands.id');
            $brandsQuery->whereIn('id', $brandIds);
        }
        $allBrands = $brandsQuery->get();
        $brandCount = $allBrands->count();
        $brandLimitExceeded = $brandCount > $maxBrands;

        // Build per-brand metrics (only for brands within plan limit for owner/admin)
        $brandsWithMetrics = [];
        $companyTotalAssets = 0;
        $companyStorageBytes = 0;
        $companyDownloadLinks = 0;

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        foreach ($allBrands as $index => $brand) {
            $isDisabled = $brandLimitExceeded && $index >= $maxBrands;

            $totalAssets = $this->getCompletedAssetsQuery($tenant->id, $brand->id)->count();
            $totalAssetsLastMonth = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
                ->where('created_at', '<=', $endOfLastMonth)
                ->count();
            $assetsChange = $totalAssetsLastMonth > 0
                ? round((($totalAssets - $totalAssetsLastMonth) / $totalAssetsLastMonth) * 100, 2)
                : ($totalAssets > 0 ? 100 : 0);

            $storageBytes = $this->getCompletedAssetsQuery($tenant->id, $brand->id)->sum('size_bytes');
            $storageMB = round($storageBytes / 1024 / 1024, 2);
            $storageBytesLastMonth = $this->getCompletedAssetsQuery($tenant->id, $brand->id)
                ->where('created_at', '<=', $endOfLastMonth)
                ->sum('size_bytes');
            $storageMBLastMonth = round($storageBytesLastMonth / 1024 / 1024, 2);
            $storageChange = $storageMBLastMonth > 0
                ? round((($storageMB - $storageMBLastMonth) / $storageMBLastMonth) * 100, 2)
                : ($storageMB > 0 ? 100 : 0);

            $downloadLinksThisMonth = Download::where('tenant_id', $tenant->id)
                ->where('status', DownloadStatus::READY)
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->whereHas('assets', fn ($q) => $q->where('brand_id', $brand->id))
                ->count();
            $downloadLinksLastMonth = Download::where('tenant_id', $tenant->id)
                ->where('status', DownloadStatus::READY)
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->whereHas('assets', fn ($q) => $q->where('brand_id', $brand->id))
                ->count();
            $downloadChange = $downloadLinksLastMonth > 0
                ? round((($downloadLinksThisMonth - $downloadLinksLastMonth) / $downloadLinksLastMonth) * 100, 2)
                : ($downloadLinksThisMonth > 0 ? 100 : 0);

            if (! $isDisabled) {
                $companyTotalAssets += $totalAssets;
                $companyStorageBytes += $storageBytes;
                $companyDownloadLinks += $downloadLinksThisMonth;
            }

            $brandsWithMetrics[] = [
                'id' => $brand->id,
                'name' => $brand->name,
                'logo_path' => $brand->logo_path,
                'icon_path' => $brand->icon_path,
                'icon' => $brand->icon,
                'icon_bg_color' => $brand->icon_bg_color,
                'primary_color' => $brand->primary_color,
                'is_default' => $brand->is_default,
                'is_disabled' => $isDisabled,
                'stats' => [
                    'total_assets' => ['value' => $totalAssets, 'change' => $assetsChange, 'is_positive' => $assetsChange >= 0],
                    'storage_mb' => ['value' => $storageMB, 'change' => $storageChange, 'is_positive' => $storageChange >= 0, 'limit' => null],
                    'download_links' => ['value' => $downloadLinksThisMonth, 'change' => $downloadChange, 'is_positive' => $downloadChange >= 0, 'limit' => null],
                ],
            ];
        }

        // Company totals (last month for change calc)
        $companyTotalAssetsLastMonth = 0;
        $companyStorageBytesLastMonth = 0;
        $companyDownloadLinksLastMonth = 0;
        foreach ($allBrands as $index => $brand) {
            if ($brandLimitExceeded && $index >= $maxBrands) {
                continue;
            }
            $companyTotalAssetsLastMonth += $this->getCompletedAssetsQuery($tenant->id, $brand->id)
                ->where('created_at', '<=', $endOfLastMonth)
                ->count();
            $companyStorageBytesLastMonth += $this->getCompletedAssetsQuery($tenant->id, $brand->id)
                ->where('created_at', '<=', $endOfLastMonth)
                ->sum('size_bytes');
            $companyDownloadLinksLastMonth += Download::where('tenant_id', $tenant->id)
                ->where('status', DownloadStatus::READY)
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->whereHas('assets', fn ($q) => $q->where('brand_id', $brand->id))
                ->count();
        }

        $companyStorageMB = round($companyStorageBytes / 1024 / 1024, 2);
        $companyStorageMBLastMonth = round($companyStorageBytesLastMonth / 1024 / 1024, 2);
        $companyAssetsChange = $companyTotalAssetsLastMonth > 0
            ? round((($companyTotalAssets - $companyTotalAssetsLastMonth) / $companyTotalAssetsLastMonth) * 100, 2)
            : ($companyTotalAssets > 0 ? 100 : 0);
        $companyStorageChange = $companyStorageMBLastMonth > 0
            ? round((($companyStorageMB - $companyStorageMBLastMonth) / $companyStorageMBLastMonth) * 100, 2)
            : ($companyStorageMB > 0 ? 100 : 0);
        $companyDownloadChange = $companyDownloadLinksLastMonth > 0
            ? round((($companyDownloadLinks - $companyDownloadLinksLastMonth) / $companyDownloadLinksLastMonth) * 100, 2)
            : ($companyDownloadLinks > 0 ? 100 : 0);

        $maxStorageMB = $planLimits['max_storage_mb'] ?? null;
        $maxDownloadsPerMonth = $planLimits['max_downloads_per_month'] ?? null;

        $hasBrandSettingsManage = $user->hasPermissionForTenant($tenant, 'brand_settings.manage');
        $canCreateBrand = $hasBrandSettingsManage && $this->brandService->canCreate($tenant);

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

        return Inertia::render('Company/Overview', [
            'tenant' => $tenant,
            'activeBrand' => $activeBrand,
            'canCreateBrand' => $canCreateBrand,
            'canManageBrands' => $hasBrandSettingsManage,
            'plan' => [
                'name' => $planDisplayName,
                'key' => $planName,
            ],
            'companyStats' => [
                'total_assets' => [
                    'value' => $companyTotalAssets,
                    'change' => $companyAssetsChange,
                    'is_positive' => $companyAssetsChange >= 0,
                ],
                'storage_mb' => [
                    'value' => $companyStorageMB,
                    'change' => $companyStorageChange,
                    'is_positive' => $companyStorageChange >= 0,
                    'limit' => $maxStorageMB,
                ],
                'download_links' => [
                    'value' => $companyDownloadLinks,
                    'change' => $companyDownloadChange,
                    'is_positive' => $companyDownloadChange >= 0,
                    'limit' => $maxDownloadsPerMonth,
                ],
            ],
            'brands' => $brandsWithMetrics,
            'ai_usage' => $aiUsageData,
        ]);
    }

    protected function getCompletedAssetsQuery(int $tenantId, int $brandId)
    {
        return Asset::where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('status', AssetStatus::VISIBLE)
            ->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->where('metadata->ai_tagging_completed', true)
            ->where('metadata->metadata_extracted', true)
            ->where(function ($query) {
                $query->where('metadata->preview_generated', true)
                    ->orWhereNull('metadata->preview_generated');
            })
            ->whereNull('deleted_at');
    }
}
