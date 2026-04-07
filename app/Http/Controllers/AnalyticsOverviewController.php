<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Collection;
use App\Models\Download;
use App\Services\AiUsageService;
use App\Services\FeatureGate;
use App\Services\Insights\BrandActivityFeedService;
use App\Services\MetadataAnalyticsService;
use App\Services\PlanService;
use App\Services\Prostaff\GetProstaffInsightsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analytics Overview — combined metrics from Brand Dashboard + Metadata Analytics.
 * Part of CINEMATIC OVERVIEW + ANALYTICS RESTRUCTURE.
 */
class AnalyticsOverviewController extends Controller
{
    public function __construct(
        protected PlanService $planService,
        protected AiUsageService $aiUsageService,
        protected MetadataAnalyticsService $metadataAnalytics
    ) {}

    /**
     * Display the analytics overview (combined metrics).
     * GET /app/insights/overview
     */
    public function index(Request $request): Response
    {
        // Fast first paint: skip MetadataAnalyticsService on the document request; the page fetches it via XHR.
        $data = $this->getOverviewData($request, includeMetadataAnalytics: false);

        $tenant = app('tenant');
        $brand = app('brand');
        $creatorModuleEnabled = $tenant && app(FeatureGate::class)->creatorModuleEnabled($tenant);
        $creatorInsights = null;
        if ($creatorModuleEnabled && $brand) {
            $creatorInsights = app(GetProstaffInsightsData::class)->forBrand($brand);
        }

        return Inertia::render('Insights/Overview', [
            'stats' => [
                'total_assets' => $data['total_assets'],
                'storage_mb' => $data['storage_mb'],
                'storage_limit_mb' => $data['max_storage_mb'],
                'downloads' => $data['downloads'],
                'downloads_limit' => $data['max_downloads_per_month'],
                'collections' => $data['collections'],
                'executions' => $data['executions'],
            ],
            'ai_usage' => $data['ai_usage'],
            'ai_monthly_cap_alert' => $data['ai_monthly_cap_alert'],
            'plan' => $data['plan'],
            'brand_guidelines' => $data['brand_guidelines'],
            'creator_module_enabled' => (bool) $creatorModuleEnabled,
            'creator_insights' => $creatorInsights,
        ]);
    }

    /**
     * Heavy metadata analytics for Insights Overview (deferred from full page load).
     * GET /app/insights/overview/metadata-analytics
     */
    public function metadataAnalytics(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant and brand must be selected.');
        }
        $user = Auth::user();
        if (! $user->canViewBrandWorkspaceInsights($tenant, $brand)) {
            abort(403, 'You do not have permission to view insights.');
        }

        $analytics = $this->metadataAnalytics->getAnalytics(
            $tenant->id,
            $brand->id,
            null,
            null,
            null,
            false,
            ['overview', 'coverage', 'ai_effectiveness', 'rights_risk'],
        );

        return response()->json([
            'metadata_overview' => $analytics['overview'] ?? [],
            'metadata_coverage' => $analytics['coverage'] ?? [],
            'ai_effectiveness' => $analytics['ai_effectiveness'] ?? [],
            'rights_risk' => $analytics['rights_risk'] ?? [],
        ]);
    }

    /**
     * Brand-scoped activity log (same data shape as the dashboard widget, longer list).
     * GET /app/insights/activity
     */
    public function activity(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant and brand must be selected.');
        }
        $user = Auth::user();
        if (! $user->canViewBrandWorkspaceInsights($tenant, $brand)) {
            abort(403, 'You do not have permission to view insights.');
        }

        $filters = [
            'actor_id' => $request->filled('actor_id') ? max(0, (int) $request->input('actor_id')) : null,
            'event_type' => $request->filled('event_type') ? trim((string) $request->input('event_type')) : null,
            'subject_id' => $request->filled('subject_id') ? trim((string) $request->input('subject_id')) : null,
        ];
        if (($filters['actor_id'] ?? 0) <= 0) {
            $filters['actor_id'] = null;
        }
        if (($filters['event_type'] ?? '') !== '' && ! preg_match('/^[a-z0-9._-]{1,120}$/i', (string) $filters['event_type'])) {
            $filters['event_type'] = null;
        }
        if (($filters['subject_id'] ?? '') !== '' && strlen((string) $filters['subject_id']) > 64) {
            $filters['subject_id'] = substr((string) $filters['subject_id'], 0, 64);
        }
        if (($filters['subject_id'] ?? '') === '') {
            $filters['subject_id'] = null;
        }

        $service = app(BrandActivityFeedService::class);
        $feed = $service->getRecentActivity($tenant, $brand, $user, 200, array_filter($filters, fn ($v) => $v !== null && $v !== ''));

        return Inertia::render('Insights/Activity', [
            'activity' => $feed?->values()->all() ?? [],
            'can_view_activity_logs' => $feed !== null,
            'filters' => [
                'actor_id' => $filters['actor_id'],
                'event_type' => $filters['event_type'],
                'subject_id' => $filters['subject_id'],
            ],
            'filter_options' => $feed !== null ? $service->getFilterOptions($tenant, $brand, $user) : ['actors' => [], 'event_types' => []],
        ]);
    }

    /**
     * Display the analytics usage page (storage, downloads, AI usage).
     * GET /app/insights/usage
     */
    public function usage(Request $request): Response
    {
        $data = $this->getOverviewData($request, includeMetadataAnalytics: false);

        return Inertia::render('Insights/Usage', [
            'stats' => [
                'total_assets' => $data['total_assets'],
                'storage_mb' => $data['storage_mb'],
                'storage_limit_mb' => $data['max_storage_mb'],
                'downloads' => $data['downloads'],
                'downloads_limit' => $data['max_downloads_per_month'],
            ],
            'ai_usage' => $data['ai_usage'],
            'ai_monthly_cap_alert' => $data['ai_monthly_cap_alert'],
            'plan' => $data['plan'],
        ]);
    }

    /**
     * @return array{total_assets: int, storage_mb: float, max_storage_mb: ?int, downloads: int, max_downloads_per_month: ?int, collections: int, executions: int, ai_usage: ?array, ai_monthly_cap_alert: ?array, metadata_overview: array, metadata_coverage: array, ai_effectiveness: array, rights_risk: array, plan: array, brand_guidelines: array}
     */
    private function getOverviewData(Request $request, bool $includeMetadataAnalytics = true): array
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant and brand must be selected.');
        }
        $user = Auth::user();
        if (! $user->canViewBrandWorkspaceInsights($tenant, $brand)) {
            abort(403, 'You do not have permission to view insights.');
        }
        $planName = $this->planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));
        $planDisplayName = $planConfig['name'] ?? ucfirst($planName);
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxStorageMB = $planLimits['max_storage_mb'] ?? null;
        $maxDownloadsPerMonth = $planLimits['max_downloads_per_month'] ?? null;
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $completedStats = (clone $this->getCompletedAssetsQuery($tenant->id, $brand->id))
            ->selectRaw('COUNT(*) as completed_asset_count, COALESCE(SUM(size_bytes), 0) as bytes_sum')
            ->first();
        $totalAssets = (int) ($completedStats->completed_asset_count ?? 0);
        $storageBytes = (int) ($completedStats->bytes_sum ?? 0);
        $storageMB = round($storageBytes / 1024 / 1024, 2);
        $downloadLinksThisMonth = Download::where('tenant_id', $tenant->id)
            ->where('status', DownloadStatus::READY)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereHas('assets', fn ($q) => $q->where('brand_id', $brand->id))
            ->count();
        $collectionsCount = Collection::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->count();
        $executionsCount = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::DELIVERABLE)
            ->whereNull('deleted_at')
            ->count();
        $usageStatusFull = $this->aiUsageService->getUsageStatus($tenant);

        $aiUsageData = null;
        if ($user->hasPermissionForTenant($tenant, 'ai.usage.view')) {
            $aiUsageData = $this->aiUsageService->augmentAiUsageDashboardPayload([
                'tagging' => $usageStatusFull['tagging'] ?? [],
                'suggestions' => $usageStatusFull['suggestions'] ?? [],
            ], $tenant);
        }

        /** Shown when AI tagging or suggestions monthly cap is reached (same audience as insights overview). */
        $aiMonthlyCapAlert = null;
        if ($user->canViewBrandWorkspaceInsights($tenant, $brand)) {
            $exceeded = [];
            foreach (['tagging', 'suggestions'] as $feature) {
                $row = $usageStatusFull[$feature] ?? [];
                if (($row['is_exceeded'] ?? false) && ($row['cap'] ?? 0) > 0) {
                    $exceeded[] = $feature;
                }
            }
            if ($exceeded !== []) {
                $aiMonthlyCapAlert = [
                    'features' => $exceeded,
                    'reset_hint' => 'Usage resets at the start of the next calendar month. Upgrade your plan or adjust AI settings if you need more capacity.',
                ];
            }
        }

        $metadataAnalytics = $includeMetadataAnalytics
            ? $this->metadataAnalytics->getAnalytics(
                $tenant->id,
                $brand->id,
                null,
                null,
                null,
                false,
                ['overview', 'coverage', 'ai_effectiveness', 'rights_risk'],
            )
            : [];

        $brand->loadMissing('brandModel.activeVersion');
        $brandModel = $brand->brandModel;
        $hasPublishedGuidelines = $brandModel
            && $brandModel->is_enabled
            && $brandModel->active_version_id
            && $brandModel->activeVersion !== null;
        $scoringEnabled = (bool) ($brandModel?->brand_dna_scoring_enabled ?? false);
        $dnaReady = $hasPublishedGuidelines && $scoringEnabled;

        return [
            'brand_guidelines' => [
                'dna_ready' => $dnaReady,
                'has_published_guidelines' => $hasPublishedGuidelines,
                'scoring_enabled' => $scoringEnabled,
                'research_url' => route('brands.research.show', ['brand' => $brand->id]),
                'guidelines_url' => route('brands.guidelines.index', ['brand' => $brand->id]),
                'brand_settings_url' => route('brands.edit', ['brand' => $brand->id]).'?tab=strategy',
            ],
            'total_assets' => $totalAssets,
            'storage_mb' => $storageMB,
            'max_storage_mb' => $maxStorageMB,
            'downloads' => $downloadLinksThisMonth,
            'max_downloads_per_month' => $maxDownloadsPerMonth,
            'collections' => $collectionsCount,
            'executions' => $executionsCount,
            'ai_usage' => $aiUsageData,
            'ai_monthly_cap_alert' => $aiMonthlyCapAlert,
            'metadata_overview' => $metadataAnalytics['overview'] ?? [],
            'metadata_coverage' => $metadataAnalytics['coverage'] ?? [],
            'ai_effectiveness' => $metadataAnalytics['ai_effectiveness'] ?? [],
            'rights_risk' => $metadataAnalytics['rights_risk'] ?? [],
            'plan' => ['name' => $planDisplayName, 'key' => $planName],
        ];
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
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->where('type', AssetType::ASSET);
    }
}
