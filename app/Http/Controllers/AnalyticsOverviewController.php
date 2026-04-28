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
use App\Services\Insights\BrandAssetEngagementInsightsService;
use App\Services\MetadataAnalyticsService;
use App\Services\PlanService;
use App\Services\Prostaff\GetProstaffInsightsData;
use Carbon\CarbonImmutable;
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
            'storage_insight' => $data['storage_insight'] ?? null,
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
            'q' => $request->filled('q') ? trim((string) $request->input('q')) : null,
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
        if (($filters['q'] ?? '') !== '' && strlen((string) $filters['q']) > 200) {
            $filters['q'] = substr((string) $filters['q'], 0, 200);
        }
        if (($filters['q'] ?? '') === '') {
            $filters['q'] = null;
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $service = app(BrandActivityFeedService::class);
        $feedFilter = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        $paginator = $service->paginateInsightsActivity($tenant, $brand, $user, $perPage, $page, $feedFilter);

        return Inertia::render('Insights/Activity', [
            'activity' => $paginator?->items() ?? [],
            'can_view_activity_logs' => $paginator !== null,
            'pagination' => $paginator !== null ? [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ] : null,
            'filters' => [
                'actor_id' => $filters['actor_id'],
                'event_type' => $filters['event_type'],
                'subject_id' => $filters['subject_id'],
                'q' => $filters['q'],
            ],
            'filter_options' => $paginator !== null ? $service->getFilterOptions($tenant, $brand, $user) : ['actors' => [], 'event_types' => []],
        ]);
    }

    /**
     * Display the analytics usage page (storage, downloads, AI usage).
     * GET /app/insights/usage
     */
    public function usage(Request $request): Response
    {
        $data = $this->getOverviewData($request, includeMetadataAnalytics: false);

        $tenant = app('tenant');
        $brand = app('brand');
        $range = $this->resolveUsageEngagementRange($request);
        $assetEngagement = app(BrandAssetEngagementInsightsService::class)->summarize(
            $tenant,
            $brand,
            $range['start'],
            $range['end']
        );

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
            'asset_engagement' => $assetEngagement,
            'engagement_range' => [
                'preset' => $range['preset'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'label' => $range['label'],
            ],
            'storage_insight' => $data['storage_insight'] ?? null,
        ]);
    }

    /**
     * @return array{preset: string, start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    private function resolveUsageEngagementRange(Request $request): array
    {
        $tz = config('app.timezone') ?: 'UTC';
        $now = CarbonImmutable::now($tz);
        $preset = strtolower((string) $request->query('range', 'this_month'));
        if (! in_array($preset, ['this_month', 'last_30', 'last_7', 'custom'], true)) {
            $preset = 'this_month';
        }

        if ($preset === 'last_7') {
            $start = $now->subDays(6)->startOfDay();
            $end = $now->endOfDay();

            return ['preset' => 'last_7', 'start' => $start, 'end' => $end, 'label' => 'Last 7 days'];
        }

        if ($preset === 'last_30') {
            $start = $now->subDays(29)->startOfDay();
            $end = $now->endOfDay();

            return ['preset' => 'last_30', 'start' => $start, 'end' => $end, 'label' => 'Last 30 days'];
        }

        if ($preset === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            try {
                $start = CarbonImmutable::parse((string) $request->query('start_date'), $tz)->startOfDay();
                $end = CarbonImmutable::parse((string) $request->query('end_date'), $tz)->endOfDay();
            } catch (\Throwable) {
                return $this->defaultUsageEngagementThisMonth($now);
            }

            if ($end->lessThan($start)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            if ($start->diffInDays($end) > BrandAssetEngagementInsightsService::MAX_RANGE_DAYS) {
                $start = $end->subDays(BrandAssetEngagementInsightsService::MAX_RANGE_DAYS)->startOfDay();
            }

            $todayEnd = $now->endOfDay();
            if ($end->greaterThan($todayEnd)) {
                $end = $todayEnd;
            }

            if ($start->greaterThan($end)) {
                return $this->defaultUsageEngagementThisMonth($now);
            }

            return [
                'preset' => 'custom',
                'start' => $start,
                'end' => $end,
                'label' => $start->format('M j, Y').' – '.$end->format('M j, Y'),
            ];
        }

        return $this->defaultUsageEngagementThisMonth($now);
    }

    /**
     * @return array{preset: string, start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    private function defaultUsageEngagementThisMonth(CarbonImmutable $now): array
    {
        return [
            'preset' => 'this_month',
            'start' => $now->startOfMonth()->startOfDay(),
            'end' => $now->endOfMonth()->endOfDay(),
            'label' => 'This month ('.$now->format('F Y').')',
        ];
    }

    /**
     * @return array{total_assets: int, storage_mb: float, max_storage_mb: ?int, downloads: int, max_downloads_per_month: ?int, collections: int, executions: int, ai_usage: ?array, ai_monthly_cap_alert: ?array, metadata_overview: array, metadata_coverage: array, ai_effectiveness: array, rights_risk: array, plan: array, brand_guidelines: array, storage_insight: array<string, mixed>}
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

        $archivedBrandBytes = (int) (Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereNotNull('archived_at')
            ->whereNull('deleted_at')
            ->sum('size_bytes') ?? 0);

        $activeVisibleBrandBytes = (int) (Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->sum('size_bytes') ?? 0);

        $storageInsight = $this->buildBrandStorageInsightPayload($archivedBrandBytes, $activeVisibleBrandBytes);
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
                'credits_used' => $usageStatusFull['credits_used'],
                'credits_cap' => $usageStatusFull['credits_cap'],
                'credits_remaining' => $usageStatusFull['credits_remaining'],
                'credits_percentage' => $usageStatusFull['credits_percentage'],
                'is_unlimited' => $usageStatusFull['is_unlimited'],
                'is_exceeded' => $usageStatusFull['is_exceeded'],
                'warning_level' => $usageStatusFull['warning_level'],
                'per_feature' => $usageStatusFull['per_feature'],
            ], $tenant);
        }

        $aiMonthlyCapAlert = null;
        if ($user->canViewBrandWorkspaceInsights($tenant, $brand)) {
            if (($usageStatusFull['is_exceeded'] ?? false)) {
                $aiMonthlyCapAlert = [
                    'features' => ['credits'],
                    'reset_hint' => 'AI credit budget exceeded. Usage resets at the start of the next calendar month. Upgrade your plan or purchase a credit add-on.',
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
            'storage_insight' => $storageInsight,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBrandStorageInsightPayload(int $archivedBytes, int $activeVisibleBytes): array
    {
        $std = (float) config('insights_storage.aws_s3_list_usd_per_gb_month.standard', 0.023);
        $ia = (float) config('insights_storage.aws_s3_list_usd_per_gb_month.standard_ia', 0.0125);
        $ratio = $std > 0 ? round($ia / $std, 4) : null;
        $discountPct = $ratio !== null ? (int) round((1 - $ratio) * 100) : null;

        $toGb = static fn (int $bytes): float => $bytes > 0 ? $bytes / (1024 ** 3) : 0.0;

        $impliedArchivedIa = round($toGb($archivedBytes) * $ia, 4);
        $impliedArchivedStd = round($toGb($archivedBytes) * $std, 4);
        $impliedActiveStd = round($toGb($activeVisibleBytes) * $std, 4);

        return [
            'archived_mb' => round($archivedBytes / 1024 / 1024, 2),
            'active_visible_mb' => round($activeVisibleBytes / 1024 / 1024, 2),
            'archived_bytes' => $archivedBytes,
            'active_visible_bytes' => $activeVisibleBytes,
            'pricing' => [
                'currency' => 'USD',
                'standard_usd_per_gb_month' => $std,
                'standard_ia_usd_per_gb_month' => $ia,
                'standard_usd_per_mb_month' => round($std / 1024, 6),
                'standard_ia_usd_per_mb_month' => round($ia / 1024, 6),
                'ia_vs_standard_ratio' => $ratio,
                'standard_ia_discount_percent_storage_tier' => $discountPct,
                'disclaimer' => (string) config('insights_storage.disclaimer', ''),
                'archive_storage_class' => (string) config('insights_storage.archive_storage_class', 'STANDARD_IA'),
            ],
            'implied_list_storage_tier_usd_month' => [
                'archived_at_standard_ia_rate' => $impliedArchivedIa,
                'archived_if_still_standard_rate' => $impliedArchivedStd,
                'active_visible_at_standard_rate' => $impliedActiveStd,
            ],
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
