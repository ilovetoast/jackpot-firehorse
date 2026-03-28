<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\DownloadStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Services\AiUsageService;
use App\Services\BrandService;
use App\Support\DashboardLinks;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
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
    ) {}

    /**
     * Display company overview with metrics across all brands.
     * GET /app
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $tenant = app('tenant');
        $activeBrand = app('brand');
        $user = Auth::user();

        if (! $tenant) {
            return redirect()->route('companies.index')->with('warning', 'Select a company to continue.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'company.view')) {
            return redirect()->route('assets.index')->with('warning', 'You don\'t have access to the company overview.');
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

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        $completedAssetMetricsByBrand = $this->completedAssetMetricsByBrandIds(
            $allBrands->pluck('id')->all(),
            $tenant->id,
            $endOfLastMonth
        );

        // Build per-brand metrics (only for brands within plan limit for owner/admin)
        $brandsWithMetrics = [];
        $companyTotalAssets = 0;
        $companyStorageBytes = 0;
        $companyDownloadLinks = 0;

        foreach ($allBrands as $index => $brand) {
            $isDisabled = $brandLimitExceeded && $index >= $maxBrands;

            $m = $completedAssetMetricsByBrand[$brand->id] ?? [
                'total_assets' => 0,
                'assets_last_month' => 0,
                'total_storage' => 0.0,
                'storage_last_month' => 0.0,
            ];
            $totalAssets = $m['total_assets'];
            $totalAssetsLastMonth = $m['assets_last_month'];
            $assetsChange = $totalAssetsLastMonth > 0
                ? round((($totalAssets - $totalAssetsLastMonth) / $totalAssetsLastMonth) * 100, 2)
                : ($totalAssets > 0 ? 100 : 0);

            $storageBytes = (int) $m['total_storage'];
            $storageMB = round($storageBytes / 1024 / 1024, 2);
            $storageBytesLastMonth = (int) $m['storage_last_month'];
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
                'icon_bg_color' => $brand->icon_bg_color,
                'icon_style' => $brand->icon_style ?? 'subtle',
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
            $m = $completedAssetMetricsByBrand[$brand->id] ?? [
                'assets_last_month' => 0,
                'storage_last_month' => 0.0,
            ];
            $companyTotalAssetsLastMonth += $m['assets_last_month'];
            $companyStorageBytesLastMonth += (int) $m['storage_last_month'];
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

        $agencyManagedBrands = [];
        if ($tenant->is_agency) {
            $managedClients = app(CompanyController::class)->managedAgencyClientsForUser($user, $tenant);
            foreach ($managedClients as $clientData) {
                $clientTenantId = (int) $clientData['id'];
                $brandMetas = $clientData['brands'] ?? [];
                if ($brandMetas === []) {
                    continue;
                }
                $brandIds = array_map(fn ($b) => (int) $b['id'], $brandMetas);
                $metricsByBrand = $this->completedAssetMetricsByBrandIds($brandIds, $clientTenantId, $endOfLastMonth);

                foreach ($brandMetas as $bm) {
                    $bid = (int) $bm['id'];
                    $brand = Brand::query()
                        ->where('tenant_id', $clientTenantId)
                        ->where('id', $bid)
                        ->first();
                    if (! $brand) {
                        continue;
                    }
                    $m = $metricsByBrand[$bid] ?? [
                        'total_assets' => 0,
                        'assets_last_month' => 0,
                        'total_storage' => 0.0,
                        'storage_last_month' => 0.0,
                    ];
                    $totalAssets = $m['total_assets'];
                    $totalAssetsLastMonth = $m['assets_last_month'];
                    $assetsChange = $totalAssetsLastMonth > 0
                        ? round((($totalAssets - $totalAssetsLastMonth) / $totalAssetsLastMonth) * 100, 2)
                        : ($totalAssets > 0 ? 100 : 0);

                    $storageBytes = (int) $m['total_storage'];
                    $storageMB = round($storageBytes / 1024 / 1024, 2);
                    $storageBytesLastMonth = (int) $m['storage_last_month'];
                    $storageMBLastMonth = round($storageBytesLastMonth / 1024 / 1024, 2);
                    $storageChange = $storageMBLastMonth > 0
                        ? round((($storageMB - $storageMBLastMonth) / $storageMBLastMonth) * 100, 2)
                        : ($storageMB > 0 ? 100 : 0);

                    $downloadLinksThisMonth = Download::where('tenant_id', $clientTenantId)
                        ->where('status', DownloadStatus::READY)
                        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                        ->whereHas('assets', fn ($q) => $q->where('brand_id', $bid))
                        ->count();
                    $downloadLinksLastMonth = Download::where('tenant_id', $clientTenantId)
                        ->where('status', DownloadStatus::READY)
                        ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                        ->whereHas('assets', fn ($q) => $q->where('brand_id', $bid))
                        ->count();
                    $downloadChange = $downloadLinksLastMonth > 0
                        ? round((($downloadLinksThisMonth - $downloadLinksLastMonth) / $downloadLinksLastMonth) * 100, 2)
                        : ($downloadLinksThisMonth > 0 ? 100 : 0);

                    $agencyManagedBrands[] = [
                        'client_tenant_id' => $clientTenantId,
                        'client_name' => $clientData['name'],
                        'brand' => [
                            'id' => $brand->id,
                            'name' => $brand->name,
                            'logo_path' => $brand->logoUrlForGuest(false),
                            'icon_bg_color' => $brand->icon_bg_color,
                            'icon_style' => $brand->icon_style ?? 'subtle',
                            'primary_color' => $brand->primary_color,
                            'is_default' => (bool) ($bm['is_default'] ?? false),
                            'is_disabled' => false,
                            'stats' => [
                                'total_assets' => ['value' => $totalAssets, 'change' => $assetsChange, 'is_positive' => $assetsChange >= 0],
                                'storage_mb' => ['value' => $storageMB, 'change' => $storageChange, 'is_positive' => $storageChange >= 0, 'limit' => null],
                                'download_links' => ['value' => $downloadLinksThisMonth, 'change' => $downloadChange, 'is_positive' => $downloadChange >= 0, 'limit' => null],
                            ],
                        ],
                    ];
                }
            }
        }

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

        $tenant->loadMissing('defaultBrand');
        $brandForPortal = $activeBrand ?? $tenant->defaultBrand;
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
            'agency_managed_brands' => $agencyManagedBrands,
            'dashboard_links' => [
                'brand' => DashboardLinks::brandPortalHref($user, $tenant, $brandForPortal),
                'brand_label' => DashboardLinks::workspaceSettingsLabels($tenant->name, $brandForPortal?->name)['brand'],
            ],
        ]);
    }

    /**
     * One grouped query for “completed” assets per brand (same rules as dashboard grid).
     *
     * @param  list<int>  $brandIds
     * @return array<int, array{total_assets: int, assets_last_month: int, total_storage: float, storage_last_month: float}>
     */
    protected function completedAssetMetricsByBrandIds(array $brandIds, int $tenantId, Carbon $endOfLastMonth): array
    {
        if ($brandIds === []) {
            return [];
        }

        $cutoff = $endOfLastMonth->format('Y-m-d H:i:s');

        $rows = Asset::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('brand_id', $brandIds)
            ->where('status', AssetStatus::VISIBLE)
            ->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->where('metadata->ai_tagging_completed', true)
            ->where('metadata->metadata_extracted', true)
            ->where(function ($query) {
                $query->where('metadata->preview_generated', true)
                    ->orWhereNull('metadata->preview_generated');
            })
            ->whereNull('deleted_at')
            ->groupBy('brand_id')
            ->selectRaw(
                'brand_id, COUNT(*) as total_assets, '
                .'SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) as assets_last_month, '
                .'COALESCE(SUM(COALESCE(size_bytes, 0)), 0) as total_storage, '
                .'SUM(CASE WHEN created_at <= ? THEN COALESCE(size_bytes, 0) ELSE 0 END) as storage_last_month',
                [$cutoff, $cutoff]
            )
            ->get();

        /** @var array<int, array{total_assets: int, assets_last_month: int, total_storage: float, storage_last_month: float}> $metrics */
        $metrics = array_fill_keys(
            array_map('intval', $brandIds),
            [
                'total_assets' => 0,
                'assets_last_month' => 0,
                'total_storage' => 0.0,
                'storage_last_month' => 0.0,
            ]
        );

        foreach ($rows as $row) {
            $bid = (int) $row->brand_id;
            $metrics[$bid] = [
                'total_assets' => (int) $row->total_assets,
                'assets_last_month' => (int) $row->assets_last_month,
                'total_storage' => (float) $row->total_storage,
                'storage_last_month' => (float) $row->storage_last_month,
            ];
        }

        return $metrics;
    }
}
