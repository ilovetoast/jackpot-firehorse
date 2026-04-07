<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadStatus;
use App\Enums\MetricType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\AssetCompletionService;
use App\Services\BrandGateway\BrandThemeBuilder;
use App\Services\BrandInsightEngine;
use App\Services\FeatureGate;
use App\Services\Insights\BrandActivityFeedService;
use App\Services\PlanService;
use App\Support\AssetVariant;
use App\Support\DashboardLinks;
use App\Support\DeliveryContext;
use App\Support\ThumbnailMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected PlanService $planService,
        protected AiUsageService $aiUsageService
    ) {}

    /**
     * Display the cinematic brand overview.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        return $this->buildDashboardResponse($request, 'Overview/Index');
    }

    /**
     * Display the detailed brand dashboard (stat cards, activity, top assets).
     */
    public function dashboard(Request $request): Response|RedirectResponse
    {
        return $this->buildDashboardResponse($request, 'Overview/Dashboard');
    }

    private function buildDashboardResponse(Request $request, string $view): Response|RedirectResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        if (! $tenant || ! $brand) {
            return redirect()->route('assets.index')->with('warning', 'Select a company and brand to view the overview.');
        }

        if ($view === 'Overview/Index') {
            return Inertia::render('Overview/Index', []);
        }

        $user = Auth::user();
        $metrics = $this->buildBrandOverviewMetricsProps($tenant, $brand, $user, $request);
        $assetPayloads = $this->buildOverviewPageAssetPayloads($tenant, $brand, $user);

        return Inertia::render($view, array_merge($metrics, [
            'collage_assets' => $assetPayloads['collage_assets'],
            'most_viewed_assets' => $assetPayloads['most_viewed_assets'],
            'most_downloaded_assets' => $assetPayloads['most_downloaded_assets'],
            'most_trending_assets' => $assetPayloads['most_trending_assets'],
            'overview_assets_deferred' => false,
            'brand_signals' => [],
            'momentum_data' => [],
            'ai_insights' => [],
            'insights_deferred' => false,
        ]));
    }

    /**
     * Heavy overview metrics (stats, theme, activity, pending counts). Used by Overview/Dashboard SSR and /api/overview/metrics.
     *
     * @return array<string, mixed>
     */
    protected function buildBrandOverviewMetricsProps(Tenant $tenant, Brand $brand, User $user, Request $request): array
    {
        // Get current plan name
        $planName = $this->planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planName}", config('plans.free'));
        $planDisplayName = $planConfig['name'] ?? ucfirst($planName);

        // Check if user is owner or admin
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

        $collectionsCount = Collection::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->count();

        $executionsCount = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', \App\Enums\AssetType::DELIVERABLE)
            ->whereNull('deleted_at')
            ->count();

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
            $aiUsageData = $this->aiUsageService->augmentAiUsageDashboardPayload($aiUsageData, $tenant);
        }

        // Phase M-1: Pending AI suggestions — same per-user asset scope as AI Review / notification bell
        $aiScope = app(\App\Services\AiReviewSuggestionScopeService::class);
        $pendingMetadataQuery = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai');
        $aiScope->scopeQueryToAiReviewAssetVisibility($pendingMetadataQuery, $user, $brand);
        $pendingMetadataCount = (int) $pendingMetadataQuery->count();

        $pendingTagQuery = DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at');
        $aiScope->scopeQueryToAiReviewAssetVisibility($pendingTagQuery, $user, $brand);
        $pendingTagCount = (int) $pendingTagQuery->count();

        // Phase M-1: Exclude asset_metadata.approved_at IS NULL from pending count
        // Metadata is reviewed inline during asset review, not as separate suggestions
        $totalPendingCount = $pendingMetadataCount + $pendingTagCount;

        // Count pending metadata approvals (from asset_metadata table, not candidates)
        // Only show to users who can approve metadata
        // TASK 2: Use same query logic as getAllPendingMetadataApprovals() API endpoint for consistency
        // Only count fields where there's NO approved row (matches AssetMetadataStateResolver logic)
        $approvalResolver = app(\App\Services\MetadataApprovalResolver::class);
        $canApprove = $approvalResolver->canApprove($user, $tenant);

        // Base query for pending metadata (field-based, not asset-based)
        $pendingMetadataBaseQuery = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
            ->whereIn('asset_metadata.source', ['ai', 'user'])
            ->where('metadata_fields.population_mode', '!=', 'automatic')
            // Exclude fields that already have an approved row (matches AssetMetadataStateResolver logic)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('asset_metadata as approved_metadata')
                    ->whereColumn('approved_metadata.asset_id', 'asset_metadata.asset_id')
                    ->whereColumn('approved_metadata.metadata_field_id', 'asset_metadata.metadata_field_id')
                    ->whereNotNull('approved_metadata.approved_at')
                    ->whereNotIn('approved_metadata.source', ['user_rejected', 'ai_rejected']);
            });

        // Only calculate count for approvers - contributors cannot approve, so they shouldn't see this tile
        // Contributors can see their own pending count in the notification bell instead
        $pendingMetadataApprovalsCount = 0;
        if ($canApprove) {
            $pendingMetadataApprovalsCount = (clone $pendingMetadataBaseQuery)
                ->count('asset_metadata.id');
        }

        // Phase L.5.1: Count unpublished assets (waiting to be published)
        // Only visible to users with metadata.bypass_approval (full viewing privileges)
        $unpublishedAssetsCount = 0;
        if ($user->hasPermissionForTenant($tenant, 'metadata.bypass_approval')) {
            $unpublishedAssetsCount = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', \App\Enums\AssetType::ASSET)
                ->whereNull('published_at') // Unpublished
                ->whereNull('archived_at') // Not archived
                ->whereNull('deleted_at') // Not deleted
                ->count();
        }

        // Phase J: Count pending assets for approval workflow
        // Approvers (Admin/Owner/Brand Manager) see all pending/rejected assets
        // Contributors see only their own pending/rejected assets
        $userRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);

        // Check if user is a brand manager
        $isBrandManager = false;
        $membership = $user->activeBrandMembership($brand);
        $isBrandManager = $membership && ($membership['role'] ?? null) === 'brand_manager';

        // Check if user is a contributor
        $isContributor = $membership && ($membership['role'] ?? null) === 'contributor';

        $pendingAssetsCount = 0;
        $contributorPendingCount = 0;
        $contributorRejectedCount = 0;

        if ($isTenantOwnerOrAdmin || $isBrandManager) {
            // Approvers: Count all pending/rejected assets in brand
            $pendingAssetsCount = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', \App\Enums\AssetType::ASSET)
                ->where(function ($query) {
                    $query->where('approval_status', \App\Enums\ApprovalStatus::PENDING)
                        ->orWhere('approval_status', \App\Enums\ApprovalStatus::REJECTED);
                })
                ->whereNull('deleted_at')
                ->count();
        } elseif ($isContributor) {
            // Phase J.3: Contributors: Count only their own pending/rejected assets
            $contributorPendingCount = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', \App\Enums\AssetType::ASSET)
                ->where('user_id', $user->id)
                ->where('approval_status', \App\Enums\ApprovalStatus::PENDING)
                ->whereNull('deleted_at')
                ->count();

            $contributorRejectedCount = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', \App\Enums\AssetType::ASSET)
                ->where('user_id', $user->id)
                ->where('approval_status', \App\Enums\ApprovalStatus::REJECTED)
                ->whereNull('deleted_at')
                ->count();

            $pendingAssetsCount = $contributorPendingCount + $contributorRejectedCount;
        }

        $recentActivity = app(BrandActivityFeedService::class)->getRecentActivity($tenant, $brand, $user, 5);

        // Widget visibility by role (defaults only; no per-tenant overrides).
        // Widget names: 'total_assets', 'storage', 'download_links', 'most_viewed', 'most_downloaded', 'most_trending', 'pending_ai_suggestions', 'pending_metadata_approvals'
        $defaultWidgetVisibility = [
            'owner' => [
                'total_assets' => true,
                'storage' => true,
                'download_links' => true,
                'most_viewed' => true,
                'most_downloaded' => true,
                'most_trending' => true,
                'pending_ai_suggestions' => true,
                'pending_metadata_approvals' => true,
                'pending_asset_approvals' => true, // Phase J.3.1: Pending asset approvals visible to approvers
            ],
            'admin' => [
                'total_assets' => true,
                'storage' => true,
                'download_links' => true,
                'most_viewed' => true,
                'most_downloaded' => true,
                'most_trending' => true,
                'pending_ai_suggestions' => true,
                'pending_metadata_approvals' => true,
                'pending_asset_approvals' => true, // Phase J.3.1: Pending asset approvals visible to approvers
            ],
            'brand_manager' => [
                'total_assets' => true,
                'storage' => true,
                'download_links' => true,
                'most_viewed' => true,
                'most_downloaded' => true,
                'most_trending' => true,
                'pending_ai_suggestions' => true,
                'pending_metadata_approvals' => true,
                'pending_asset_approvals' => true, // Phase J.3.1: Pending asset approvals visible to approvers
            ],
            'contributor' => [
                'total_assets' => false, // Contributors don't see company widgets
                'storage' => false,
                'download_links' => false,
                'most_viewed' => true,
                'most_downloaded' => true,
                'most_trending' => true,
                'pending_ai_suggestions' => false, // Permission-based widgets hidden by default for contributors
                'pending_metadata_approvals' => false,
                'pending_asset_approvals' => false, // Phase J.3.1: Contributors don't see admin approval widget
            ],
            'viewer' => [
                'total_assets' => false, // Viewers only see Most Viewed and Most Downloaded
                'storage' => false,
                'download_links' => false,
                'most_viewed' => true,
                'most_downloaded' => true,
                'most_trending' => true,
                'pending_ai_suggestions' => false, // Permission-based widgets hidden by default for viewers
                'pending_metadata_approvals' => false,
                'pending_asset_approvals' => false, // Phase J.3.1: Viewers don't see approval widgets
            ],
        ];

        // Determine current user's widget visibility
        $userRole = strtolower($userRole ?? 'viewer');
        $userWidgetVisibility = $defaultWidgetVisibility[$userRole] ?? $defaultWidgetVisibility['viewer'];

        // Admin preview hook: ?as=viewer|contributor overrides permission flags for UX testing
        // Not enforced on backend routes — purely cosmetic for dashboard tile visibility
        $previewRole = $isAdmin ? $request->query('as') : null;

        $permissions = [
            'canManageBrand' => $user->hasPermissionForTenant($tenant, 'brand_settings.manage'),
            'canManageTeam' => $user->hasPermissionForTenant($tenant, 'team.manage'),
            // Insights routes: {@see User::canViewBrandWorkspaceInsights()} — keep tile in sync
            'canViewAnalytics' => $user->canViewBrandWorkspaceInsights($tenant, $brand),
        ];

        if ($previewRole && in_array($previewRole, ['viewer', 'contributor'], true)) {
            $permissions['canManageBrand'] = false;
            $permissions['canManageTeam'] = false;
            $permissions['canViewAnalytics'] = false;
        }

        // Cinematic overview quick links (real permissions — not overridden by ?as= preview)
        $permissions['canViewBrandAssets'] = $user->hasPermissionForBrand($brand, 'asset.view');
        $permissions['canViewBrandExecutions'] = $user->hasPermissionForBrand($brand, 'asset.view');

        $permissions['showCreatorOverviewQuickLinks'] = $user->isProstaffForBrand($brand)
            && app(FeatureGate::class)->creatorModuleEnabled($tenant)
            && $user->hasPermissionForBrand($brand, 'asset.view');

        // Brand cinematic overview: link to company overview (/app) — you’re already on brand overview (no “here” crumb).
        $dashLabels = DashboardLinks::workspaceDashboardShortLabels($tenant->name, $brand->name);
        $dashboardLinks = [
            'company' => DashboardLinks::companyOverviewHref($user, $tenant),
            'company_label' => $dashLabels['company'],
        ];

        if ($previewRole && in_array($previewRole, ['viewer', 'contributor'], true)) {
            $dashboardLinks = [
                'company' => null,
                'company_label' => $dashLabels['company'],
            ];
        }

        // Tagline / presentation read brandModel->activeVersion; load once to avoid extra queries in BrandThemeBuilder.
        $brand->loadMissing(['brandModel.activeVersion']);

        $theme = app(BrandThemeBuilder::class)->build($tenant, $brand);

        return [
            'tenant' => $tenant,
            'brand' => $brand,
            'permissions' => $permissions,
            'theme' => $theme,
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
                    'limit' => $maxStorageMB, // Defined limit in MB (all plans have finite storage)
                ],
                'download_links' => [
                    'value' => $downloadLinksThisMonth, // Current month download links (Download model)
                    'change' => $downloadLinksChange,
                    'is_positive' => $downloadLinksChange >= 0,
                    'limit' => $maxDownloadsPerMonth, // Monthly download limit
                ],
                'collections_count' => $collectionsCount,
                'executions_count' => $executionsCount,
            ],
            'is_manager' => $isAdmin || $isBrandManager,
            'widget_visibility' => $userWidgetVisibility, // Widget visibility for current user's role
            'ai_usage' => $aiUsageData, // Tenant-scoped AI usage (shared across all brands)
            'recent_activity' => $recentActivity, // Recent company activity (permission-gated)
            'pending_ai_suggestions' => [
                'total' => $totalPendingCount,
                'metadata_candidates' => $pendingMetadataCount,
                'tag_candidates' => $pendingTagCount,
                // Phase M-1: pending_metadata excluded - metadata approval is asset-centric
            ],
            'pending_metadata_approvals_count' => $pendingMetadataApprovalsCount, // Count of pending metadata approvals (for approvers only)
            'unpublished_assets_count' => $unpublishedAssetsCount, // Phase L.5.1: Count of unpublished assets
            'pending_assets_count' => $pendingAssetsCount, // Phase J: Count of pending/rejected assets (approvers see all, contributors see own)
            // Phase J.3: Contributor-specific counts (informational only)
            'contributor_pending_count' => $contributorPendingCount, // Contributor's own pending assets
            'contributor_rejected_count' => $contributorRejectedCount, // Contributor's own rejected assets
            'dashboard_links' => $dashboardLinks, // Subtle header links on cinematic Overview (permission-gated)
        ];
    }

    /**
     * Collage + most viewed / downloaded / trending payloads for overview pages (heavy queries — optional to defer for Overview/Index).
     *
     * @return array{collage_assets: \Illuminate\Support\Collection, most_viewed_assets: \Illuminate\Support\Collection, most_downloaded_assets: \Illuminate\Support\Collection, most_trending_assets: \Illuminate\Support\Collection}
     */
    protected function buildOverviewPageAssetPayloads(Tenant $tenant, Brand $brand, User $user): array
    {
        // Get most viewed assets (top 6) - only visible, non-deleted assets
        // Filter by category permissions to respect protected categories
        $mostViewedAssetIds = AssetMetric::where('asset_metrics.tenant_id', $tenant->id)
            ->where('asset_metrics.brand_id', $brand->id)
            ->where('asset_metrics.metric_type', MetricType::VIEW->value)
            ->join('assets', 'asset_metrics.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.published_at')
            ->whereNull('assets.archived_at')
            ->where(function ($q) {
                $q->where('assets.builder_staged', false)->orWhereNull('assets.builder_staged');
            })
            ->where(function ($q) {
                $q->where('assets.intake_state', 'normal')->orWhereNull('assets.intake_state');
            })
            ->where('assets.type', AssetType::ASSET->value)
            ->select('assets.id', DB::raw('COUNT(asset_metrics.id) as view_count'))
            ->groupBy('assets.id')
            ->orderByDesc('view_count')
            ->limit(25) // Get more to filter by permissions and allow up to 15 displayed
            ->pluck('view_count', 'id');

        $mostViewedAssets = collect();
        if ($mostViewedAssetIds->isNotEmpty()) {
            $assets = Asset::whereIn('id', $mostViewedAssetIds->keys())
                ->get()
                ->keyBy('id');

            // Eager load categories to avoid N+1 queries
            // Extract category IDs from asset metadata
            $categoryIds = $assets->map(function ($asset) {
                $metadata = $asset->metadata;
                if (! is_array($metadata)) {
                    return null;
                }

                return $metadata['category_id'] ?? null;
            })->filter()->unique()->values()->all();

            $categories = collect();
            if (! empty($categoryIds)) {
                $categories = Category::with(['tenant', 'brand'])->whereIn('id', $categoryIds)
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->get()
                    ->keyBy('id');
            }

            $mostViewedAssets = $mostViewedAssetIds->map(function ($viewCount, $assetId) use ($assets, $categories, $user) {
                $asset = $assets->get($assetId);
                if (! $asset) {
                    return null;
                }

                // Get category from eager-loaded collection
                $metadata = $asset->metadata;
                $categoryId = (is_array($metadata) && isset($metadata['category_id'])) ? $metadata['category_id'] : null;
                $category = $categoryId ? $categories->get($categoryId) : null;

                // Check if user can view the asset's category (respects protected categories)
                if ($category) {
                    $canViewCategory = $user->can('view', $category);
                    if (! $canViewCategory) {
                        return null; // Skip assets in protected categories user can't access
                    }
                }

                // Generate thumbnail URLs (CDN URLs from Asset model)
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');

                $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;

                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnailStyle = ThumbnailMetadata::stylePath($metadata, 'large') ? 'large' : 'medium';
                    $variant = $thumbnailStyle === 'large' ? AssetVariant::THUMB_LARGE : AssetVariant::THUMB_MEDIUM;
                    $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                    if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                        $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
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
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ] : null,
                ];
            })->filter()->take(15)->values(); // Take top 15 after filtering
        }

        // Get most downloaded assets (top 6) - only visible, non-deleted assets
        // Filter by category permissions to respect protected categories
        $mostDownloadedAssetIds = AssetMetric::where('asset_metrics.tenant_id', $tenant->id)
            ->where('asset_metrics.brand_id', $brand->id)
            ->where('asset_metrics.metric_type', MetricType::DOWNLOAD->value)
            ->join('assets', 'asset_metrics.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.published_at')
            ->whereNull('assets.archived_at')
            ->where(function ($q) {
                $q->where('assets.builder_staged', false)->orWhereNull('assets.builder_staged');
            })
            ->where(function ($q) {
                $q->where('assets.intake_state', 'normal')->orWhereNull('assets.intake_state');
            })
            ->where('assets.type', AssetType::ASSET->value)
            ->select('assets.id', DB::raw('COUNT(asset_metrics.id) as download_count'))
            ->groupBy('assets.id')
            ->orderByDesc('download_count')
            ->limit(25) // Get more to filter by permissions and allow up to 15 displayed
            ->pluck('download_count', 'id');

        $mostDownloadedAssets = collect();
        if ($mostDownloadedAssetIds->isNotEmpty()) {
            $assets = Asset::whereIn('id', $mostDownloadedAssetIds->keys())
                ->get()
                ->keyBy('id');

            // Eager load categories to avoid N+1 queries
            // Extract category IDs from asset metadata
            $categoryIds = $assets->map(function ($asset) {
                $metadata = $asset->metadata;
                if (! is_array($metadata)) {
                    return null;
                }

                return $metadata['category_id'] ?? null;
            })->filter()->unique()->values()->all();

            $categories = collect();
            if (! empty($categoryIds)) {
                $categories = Category::with(['tenant', 'brand'])->whereIn('id', $categoryIds)
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->get()
                    ->keyBy('id');
            }

            $mostDownloadedAssets = $mostDownloadedAssetIds->map(function ($downloadCount, $assetId) use ($assets, $categories, $user) {
                $asset = $assets->get($assetId);
                if (! $asset) {
                    return null;
                }

                // Get category from eager-loaded collection
                $metadata = $asset->metadata;
                $categoryId = (is_array($metadata) && isset($metadata['category_id'])) ? $metadata['category_id'] : null;
                $category = $categoryId ? $categories->get($categoryId) : null;

                // Check if user can view the asset's category (respects protected categories)
                if ($category) {
                    $canViewCategory = $user->can('view', $category);
                    if (! $canViewCategory) {
                        return null; // Skip assets in protected categories user can't access
                    }
                }

                // Generate thumbnail URLs (CDN URLs from Asset model)
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');

                $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;

                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnailStyle = ThumbnailMetadata::stylePath($metadata, 'large') ? 'large' : 'medium';
                    $variant = $thumbnailStyle === 'large' ? AssetVariant::THUMB_LARGE : AssetVariant::THUMB_MEDIUM;
                    $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                    if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                        $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
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
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ] : null,
                ];
            })->filter()->take(15)->values(); // Take top 15 after filtering
        }

        // Get trending assets (exponential decay over 14 days) - same algorithm as AssetSortService
        $cutoff = now()->subDays(14)->toDateTimeString();
        $driver = DB::connection()->getDriverName();
        $decayExpr = $driver === 'pgsql'
            ? 'SUM(EXP(-0.1 * EXTRACT(EPOCH FROM (NOW() - asset_metrics.created_at)) / 86400))'
            : 'SUM(EXP(-0.1 * DATEDIFF(NOW(), asset_metrics.created_at)))';

        $trendingSub = DB::table('asset_metrics')
            ->select('asset_id', DB::raw("{$decayExpr} as trending_score"))
            ->where('created_at', '>=', $cutoff)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->groupBy('asset_id');

        $mostTrendingAssetIds = DB::table(DB::raw("({$trendingSub->toSql()}) as am_trending"))
            ->mergeBindings($trendingSub)
            ->join('assets', 'assets.id', '=', 'am_trending.asset_id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.published_at')
            ->whereNull('assets.archived_at')
            ->where(function ($q) {
                $q->where('assets.builder_staged', false)->orWhereNull('assets.builder_staged');
            })
            ->where(function ($q) {
                $q->where('assets.intake_state', 'normal')->orWhereNull('assets.intake_state');
            })
            ->where('assets.type', AssetType::ASSET->value)
            ->select('assets.id', 'am_trending.trending_score')
            ->orderByDesc('am_trending.trending_score')
            ->limit(25)
            ->pluck('trending_score', 'id');

        $mostTrendingAssets = collect();
        if ($mostTrendingAssetIds->isNotEmpty()) {
            $assets = Asset::whereIn('id', $mostTrendingAssetIds->keys())
                ->get()
                ->keyBy('id');

            $categoryIds = $assets->map(fn ($a) => (is_array($a->metadata ?? null) && isset($a->metadata['category_id'])) ? $a->metadata['category_id'] : null)
                ->filter()->unique()->values()->all();

            $categories = collect();
            if (! empty($categoryIds)) {
                $categories = Category::with(['tenant', 'brand'])->whereIn('id', $categoryIds)
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->get()
                    ->keyBy('id');
            }

            $mostTrendingAssets = $mostTrendingAssetIds->map(function ($trendingScore, $assetId) use ($assets, $categories, $user) {
                $asset = $assets->get($assetId);
                if (! $asset) {
                    return null;
                }

                $metadata = $asset->metadata ?? [];
                $categoryId = (is_array($metadata) && isset($metadata['category_id'])) ? $metadata['category_id'] : null;
                $category = $categoryId ? $categories->get($categoryId) : null;

                if ($category) {
                    if (! $user->can('view', $category)) {
                        return null;
                    }
                }

                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');
                $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
                $finalThumbnailUrl = null;
                if ($thumbnailStatus === 'completed') {
                    $thumbnailStyle = ThumbnailMetadata::stylePath($metadata, 'large') ? 'large' : 'medium';
                    $variant = $thumbnailStyle === 'large' ? AssetVariant::THUMB_LARGE : AssetVariant::THUMB_MEDIUM;
                    $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                        $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
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
                    'trending_score' => (float) $trendingScore,
                    'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
                ];
            })->filter()->take(15)->values();
        }

        // Collage assets: best visual quality first (compliance score), then most viewed.
        // Match main Assets library: published, non-archived, normal intake, not builder-staged, type=asset (excludes reference/research materials).
        $collageAssets = Asset::where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->where('assets.thumbnail_status', ThumbnailStatus::COMPLETED)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.published_at')
            ->whereNull('assets.archived_at')
            ->where(function ($q) {
                $q->where('assets.builder_staged', false)->orWhereNull('assets.builder_staged');
            })
            ->where(function ($q) {
                $q->where('assets.intake_state', 'normal')->orWhereNull('assets.intake_state');
            })
            ->where('assets.type', AssetType::ASSET->value)
            ->leftJoin('brand_intelligence_scores as bis_dash', function ($join) {
                $join->on('bis_dash.asset_id', '=', 'assets.id')
                    ->whereColumn('bis_dash.brand_id', 'assets.brand_id')
                    ->whereNull('bis_dash.execution_id')
                    ->whereRaw('bis_dash.id = (SELECT MAX(b2.id) FROM brand_intelligence_scores b2 WHERE b2.asset_id = assets.id AND b2.brand_id = assets.brand_id AND b2.execution_id IS NULL)');
            })
            ->leftJoin(DB::raw('(SELECT asset_id, COUNT(*) as view_count FROM asset_metrics WHERE metric_type = \''.MetricType::VIEW->value.'\' GROUP BY asset_id) as views'), 'assets.id', '=', 'views.asset_id')
            ->select('assets.*')
            ->orderByRaw('COALESCE(bis_dash.overall_score, 0) DESC')
            ->orderByRaw('COALESCE(views.view_count, 0) DESC')
            ->limit(50)
            ->get()
            ->map(function ($asset) {
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');

                if ($thumbnailStatus !== 'completed') {
                    return null;
                }

                // Collage tiles are large; only medium+ derivatives avoid blocky upscaling.
                $hasLarge = ThumbnailMetadata::stylePath($metadata, 'large') !== null;
                if (ThumbnailMetadata::stylePath($metadata, 'medium') === null && ! $hasLarge) {
                    return null;
                }

                $variant = $hasLarge ? AssetVariant::THUMB_LARGE : AssetVariant::THUMB_MEDIUM;
                $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                if (! $finalThumbnailUrl) {
                    return null;
                }

                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                if ($thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                    $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
                }

                return [
                    'id' => $asset->id,
                    'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                    'final_thumbnail_url' => $finalThumbnailUrl,
                    'thumbnail_url' => $finalThumbnailUrl,
                ];
            })->filter()->values()->take(8);

        return [
            'collage_assets' => $collageAssets,
            'most_viewed_assets' => $mostViewedAssets,
            'most_downloaded_assets' => $mostDownloadedAssets,
            'most_trending_assets' => $mostTrendingAssets,
        ];
    }

    /**
     * Fast first-paint payload for cinematic overview: brand snapshot, headline counts, small-thumb collage only.
     * No theme builder, no activity feed, no insights.
     *
     * @return array<string, mixed>
     */
    protected function buildOverviewHeroPayload(Tenant $tenant, Brand $brand, User $user): array
    {
        $totalAssets = $this->getCompletedAssetsQuery($tenant->id, $brand->id)->count();

        $executionsCount = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::DELIVERABLE)
            ->whereNull('deleted_at')
            ->count();

        return [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'headline' => [
                'total_assets' => $totalAssets,
                'executions_count' => $executionsCount,
            ],
            'collage_assets' => $this->buildOverviewHeroCollageAssets($tenant, $brand, $user),
        ];
    }

    /**
     * Lightweight collage for hero tier: recent published assets, THUMB_SMALL (thumb) only, category-respecting.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function buildOverviewHeroCollageAssets(Tenant $tenant, Brand $brand, User $user): \Illuminate\Support\Collection
    {
        $candidates = Asset::where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.status', AssetStatus::VISIBLE)
            ->where('assets.thumbnail_status', ThumbnailStatus::COMPLETED)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.published_at')
            ->whereNull('assets.archived_at')
            ->where(function ($q) {
                $q->where('assets.builder_staged', false)->orWhereNull('assets.builder_staged');
            })
            ->where(function ($q) {
                $q->where('assets.intake_state', 'normal')->orWhereNull('assets.intake_state');
            })
            ->where('assets.type', AssetType::ASSET->value)
            ->orderByDesc('assets.created_at')
            ->limit(48)
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $categoryIds = $candidates->map(function ($asset) {
            $metadata = $asset->metadata;
            if (! is_array($metadata)) {
                return null;
            }

            return $metadata['category_id'] ?? null;
        })->filter()->unique()->values()->all();

        $categories = collect();
        if (! empty($categoryIds)) {
            $categories = Category::with(['tenant', 'brand'])->whereIn('id', $categoryIds)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->get()
                ->keyBy('id');
        }

        $out = collect();
        foreach ($candidates as $asset) {
            if ($out->count() >= 10) {
                break;
            }

            $metadata = $asset->metadata ?? [];
            $categoryId = (is_array($metadata) && isset($metadata['category_id'])) ? $metadata['category_id'] : null;
            $category = $categoryId ? $categories->get($categoryId) : null;
            if ($category && ! $user->can('view', $category)) {
                continue;
            }

            $smallUrl = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
            if ($smallUrl === '') {
                continue;
            }

            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            if ($thumbnailVersion && ! str_contains($smallUrl, 'X-Amz-Signature')) {
                $smallUrl .= (str_contains($smallUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
            }

            $out->push([
                'id' => $asset->id,
                'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                'thumbnail_url' => $smallUrl,
                'final_thumbnail_url' => $smallUrl,
            ]);
        }

        return $out->values();
    }

    /**
     * JSON: fast hero tier (brand + headline counts + small-thumb collage). No insights or heavy metrics.
     */
    public function overviewHeroJson(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->buildOverviewHeroPayload($tenant, $brand, $user))
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    /**
     * JSON: stats tier (same payload as /api/overview/metrics). Alias route for progressive overview loading.
     */
    public function overviewStatsJson(Request $request): JsonResponse
    {
        return $this->overviewMetricsJson($request);
    }

    /**
     * JSON: stats, theme, permissions, activity (cinematic overview — after first paint).
     */
    public function overviewMetricsJson(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->buildBrandOverviewMetricsProps($tenant, $brand, $user, $request))
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    /**
     * JSON: deferred collage + top asset lists for cinematic overview (after first paint).
     */
    public function overviewAssetsJson(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->buildOverviewPageAssetPayloads($tenant, $brand, $user))
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    /**
     * JSON: brand signals, momentum, and AI insights for the cinematic overview (deferred load).
     */
    public function insightsJson(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $this->buildBrandInsightsPayload($brand, $user);

        return response()->json($payload)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    /**
     * @return array{brand_signals: mixed, momentum_data: mixed, ai_insights: mixed, generated_at: string}
     */
    protected function buildBrandInsightsPayload(\App\Models\Brand $brand, User $user): array
    {
        $tenantId = (int) $brand->tenant_id;
        $brandId = (int) $brand->id;
        $userId = (int) $user->id;
        $ttl = (int) config('brand_intelligence.overview_insights_ttl_seconds', 300);
        $ttl = max(60, $ttl);

        $cacheKey = sprintf('brand_overview_insights:%d:%d:%d', $tenantId, $brandId, $userId);

        return Cache::remember($cacheKey, $ttl, function () use ($brand, $user) {
            $brandSignals = app(BrandInsightEngine::class)->getSignals($brand, $user);
            $momentumData = app(BrandInsightEngine::class)->getMomentumData($brand);
            $aiInsights = $brandSignals !== []
                ? app(\App\Services\BrandInsightLLM::class)->getInsightsForBrand($brand, $brandSignals, $user)
                : [];

            return [
                'brand_signals' => $brandSignals,
                'momentum_data' => $momentumData,
                'ai_insights' => $aiInsights,
                'generated_at' => now()->toIso8601String(),
            ];
        });
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
        // Align with main /app/assets grid: library assets only (not staged, unpublished, archived, reference/research, or soft-deleted).
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
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->where('type', AssetType::ASSET);
    }
}
