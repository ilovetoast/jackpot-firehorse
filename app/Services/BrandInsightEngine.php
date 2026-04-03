<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Brand Insight Engine — zero-management AI "What Needs Attention" signals.
 *
 * Generates heuristic-based signals automatically. No user configuration.
 * Respects role-based permissions. Cached 5 minutes.
 */
class BrandInsightEngine
{
    public function __construct(
        protected MetadataAnalyticsService $metadataAnalytics,
        protected TenantPermissionResolver $tenantResolver
    ) {}

    /**
     * Get "What Needs Attention" signals for a brand.
     * Max 4 signals, sorted by priority (high → medium → low).
     *
     * @return array<array{type: string, priority: string, icon: string, label: string, href?: string, context?: array}>
     */
    public function getSignals(Brand $brand, User $user): array
    {
        // Per-user cache: counts and eligibility depend on brand role and permissions.
        $bust = (int) Cache::get('brand:'.$brand->id.':insights-bust', 0);
        $cacheKey = "brand:{$brand->id}:insights:user:{$user->id}:b{$bust}";

        return Cache::remember($cacheKey, 300, function () use ($brand, $user) {
            $signals = [];

            $tenant = $brand->tenant;
            if (! $tenant) {
                return [];
            }

            // Brand viewers: no actionable "What Needs Attention" on this surface.
            if (strtolower($user->getRoleForBrand($brand) ?? '') === 'viewer') {
                return [];
            }

            $isContributor = $this->isBrandContributor($user, $brand);

            $aiScope = app(\App\Services\AiReviewSuggestionScopeService::class);
            $canSeeAllAiReview = $aiScope->canViewBrandWideAiReviewQueues($user, $brand);
            $canSeeOthersAiReview = ! $canSeeAllAiReview && $aiScope->canAccessAiReviewApi($user, $brand);
            // Contributors: review queue is assets uploaded by teammates (not org-wide).

            $canActOnMetadataInsights = $this->userCanActOnMetadataInsights($user, $brand);
            // Contributors only ever see missing-metadata counts for assets they uploaded.
            $scopeMetadataToUploader = $canActOnMetadataInsights && $isContributor;

            $canSeeRightsInsightsTab = $user->hasPermissionForTenant($tenant, 'brand_settings.manage');

            // 1a. AI Tag Suggestions Pending (high)
            if ($canSeeAllAiReview || $canSeeOthersAiReview) {
                $tagCount = $this->getPendingAiTagSuggestionsCount($brand, $user);
                if ($tagCount > 0) {
                    $signals[] = [
                        'type' => 'action',
                        'priority' => 'high',
                        'icon' => 'sparkles',
                        'label' => "{$tagCount} AI tag suggestions to review",
                        'href' => '/app/insights/review?tab=tags',
                        'context' => [
                            'count' => $tagCount,
                            'category' => 'ai_tags',
                        ],
                    ];
                }
            }

            // 1b. AI Category Suggestions Pending (high)
            if ($canSeeAllAiReview || $canSeeOthersAiReview) {
                $categoryCount = $this->getPendingAiCategorySuggestionsCount($brand, $user);
                if ($categoryCount > 0) {
                    $signals[] = [
                        'type' => 'action',
                        'priority' => 'high',
                        'icon' => 'sparkles',
                        'label' => "{$categoryCount} AI category suggestions to review",
                        'href' => '/app/insights/review?tab=categories',
                        'context' => [
                            'count' => $categoryCount,
                            'category' => 'ai_categories',
                        ],
                    ];
                }
            }

            // 2. Missing Metadata (medium) — link to asset grid (never analytics)
            if ($canActOnMetadataInsights) {
                $assetsMissingMetadata = $scopeMetadataToUploader
                    ? $this->getAssetsMissingMetadataCountForUploader($brand, $user)
                    : $this->getAssetsMissingMetadataCount($brand);
                if ($assetsMissingMetadata > 0) {
                    $firstAssetId = $scopeMetadataToUploader
                        ? $this->getFirstAssetMissingMetadataIdForUploader($brand, $user)
                        : $this->getFirstAssetMissingMetadataId($brand);
                    $href = $firstAssetId
                        ? '/app/assets?missing_metadata=1&asset='.$firstAssetId
                        : '/app/assets?missing_metadata=1';
                    $signals[] = [
                        'type' => 'action',
                        'priority' => 'medium',
                        'icon' => 'document',
                        'label' => "{$assetsMissingMetadata} assets missing metadata",
                        'href' => $href,
                        'context' => [
                            'count' => $assetsMissingMetadata,
                            'category' => 'metadata',
                        ],
                    ];
                }
            }

            // 3. Expiring Assets (medium) — insights rights tab requires brand management access
            if ($canActOnMetadataInsights && $canSeeRightsInsightsTab) {
                $expiringCount = $this->getExpiringAssetsCount($brand);
                if ($expiringCount > 0) {
                    $signals[] = [
                        'type' => 'action',
                        'priority' => 'medium',
                        'icon' => 'clock',
                        'label' => "{$expiringCount} assets expiring soon",
                        'href' => '/app/insights/overview?tab=rights',
                        'context' => [
                            'count' => $expiringCount,
                            'category' => 'rights',
                        ],
                    ];
                }
            }

            // 4. Low Activity (low) — only when the brand already has assets (empty brand ≠ "stagnant")
            if ($this->tenantResolver->hasForBrand($user, $brand, 'asset.upload') && $this->hasLowActivity($brand)) {
                $signals[] = [
                    'type' => 'info',
                    'priority' => 'low',
                    'icon' => 'upload',
                    'label' => 'No uploads in the last 7 days',
                    'href' => '/app/assets',
                    'context' => [
                        'count' => 0,
                        'category' => 'activity',
                    ],
                ];
            }

            // Sort by priority: high → medium → low
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            usort($signals, fn ($a, $b) => ($order[$a['priority']] ?? 3) <=> ($order[$b['priority']] ?? 3));

            // Max 4 signals
            return array_slice($signals, 0, 4);
        });
    }

    /**
     * Whether the user can act on metadata/rights-style dashboard nudges (not viewers).
     */
    protected function userCanActOnMetadataInsights(User $user, Brand $brand): bool
    {
        foreach ([
            'metadata.edit_post_upload',
            'metadata.set_on_upload',
            'metadata.bypass_approval',
            'metadata.bulk_edit',
            'metadata.override_automatic',
        ] as $perm) {
            if ($this->tenantResolver->hasForBrand($user, $brand, $perm)) {
                return true;
            }
        }

        return false;
    }

    protected function isBrandContributor(User $user, Brand $brand): bool
    {
        return strtolower($user->getRoleForBrand($brand) ?? '') === 'contributor';
    }

    protected function getPendingAiTagSuggestionsCount(Brand $brand, User $user): int
    {
        $q = DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at');
        app(\App\Services\AiReviewSuggestionScopeService::class)->scopeQueryToAiReviewAssetVisibility($q, $user, $brand);

        return (int) $q->count();
    }

    protected function getPendingAiCategorySuggestionsCount(Brand $brand, User $user): int
    {
        $q = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai');
        app(\App\Services\AiReviewSuggestionScopeService::class)->scopeQueryToAiReviewAssetVisibility($q, $user, $brand);

        return (int) $q->count();
    }

    protected function getAssetsMissingMetadataCount(Brand $brand): int
    {
        $analytics = $this->metadataAnalytics->getAnalytics(
            $brand->tenant_id,
            $brand->id,
            null,
            null,
            null,
            false
        );
        $overview = $analytics['overview'] ?? [];
        $totalAssets = $overview['total_assets'] ?? 0;
        $assetsWithMetadata = $overview['assets_with_metadata'] ?? 0;

        return max(0, $totalAssets - $assetsWithMetadata);
    }

    /**
     * Get the first asset ID that has no approved metadata (for direct link to edit).
     */
    protected function getFirstAssetMissingMetadataId(Brand $brand): ?string
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return null;
        }

        $asset = DB::table('assets')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at');
            })
            ->orderBy('assets.created_at', 'desc')
            ->limit(1)
            ->value('assets.id');

        return $asset ? (string) $asset : null;
    }

    /**
     * Count of the user's own visible assets with no approved metadata (contributor scope).
     */
    protected function getAssetsMissingMetadataCountForUploader(Brand $brand, User $user): int
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return 0;
        }

        return (int) DB::table('assets')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.user_id', $user->id)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at');
            })
            ->count();
    }

    protected function getFirstAssetMissingMetadataIdForUploader(Brand $brand, User $user): ?string
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return null;
        }

        $asset = DB::table('assets')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.user_id', $user->id)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at');
            })
            ->orderBy('assets.created_at', 'desc')
            ->limit(1)
            ->value('assets.id');

        return $asset ? (string) $asset : null;
    }

    protected function getExpiringAssetsCount(Brand $brand): int
    {
        $analytics = $this->metadataAnalytics->getAnalytics(
            $brand->tenant_id,
            $brand->id,
            null,
            null,
            null,
            false
        );
        $rightsRisk = $analytics['rights_risk'] ?? [];
        $expiring30 = $rightsRisk['expiring_30_days'] ?? 0;
        $expiring60 = $rightsRisk['expiring_60_days'] ?? 0;
        $expiring90 = $rightsRisk['expiring_90_days'] ?? 0;
        $expired = $rightsRisk['expired_count'] ?? 0;

        return (int) ($expired + $expiring30 + $expiring60 + $expiring90);
    }

    /**
     * Get momentum data for Recent Momentum block.
     *
     * @return array{sharedCount: int, uploadCount: int, aiCompleted: int, teamChanges: int, sharedTrend?: float}
     */
    public function getMomentumData(Brand $brand): array
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return ['sharedCount' => 0, 'uploadCount' => 0, 'aiCompleted' => 0, 'teamChanges' => 0];
        }

        $cutoff = now()->subDays(7);

        $uploadCount = ActivityEvent::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->whereIn('event_type', $this->uploadActivityEventTypes())
            ->where('created_at', '>=', $cutoff)
            ->count();

        $sharedCount = \App\Models\Download::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $aiCompleted = ActivityEvent::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->where('event_type', EventType::ASSET_AI_SUGGESTION_ACCEPTED)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $teamEventTypes = [
            EventType::USER_ADDED_TO_COMPANY,
            EventType::USER_ADDED_TO_BRAND,
            EventType::USER_REMOVED_FROM_COMPANY,
            EventType::USER_REMOVED_FROM_BRAND,
            EventType::USER_ROLE_UPDATED,
        ];
        $teamChanges = ActivityEvent::where('tenant_id', $tenant->id)
            ->whereIn('event_type', $teamEventTypes)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $sharedLastWeek = \App\Models\Download::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereBetween('created_at', [now()->subDays(14), $cutoff])
            ->count();
        $sharedTrend = $sharedLastWeek > 0
            ? round((($sharedCount - $sharedLastWeek) / $sharedLastWeek) * 100, 1)
            : null;

        return [
            'sharedCount' => (int) $sharedCount,
            'uploadCount' => (int) $uploadCount,
            'aiCompleted' => (int) $aiCompleted,
            'teamChanges' => (int) $teamChanges,
            'sharedTrend' => $sharedTrend,
        ];
    }

    /**
     * Upload completion logs {@see EventType::ASSET_UPLOAD_FINALIZED}; legacy paths may log {@see EventType::ASSET_UPLOADED}.
     *
     * @return list<string>
     */
    protected function uploadActivityEventTypes(): array
    {
        return [EventType::ASSET_UPLOADED, EventType::ASSET_UPLOAD_FINALIZED];
    }

    protected function getTotalVisibleAssetsCount(Brand $brand): int
    {
        return (int) DB::table('assets')
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->whereNull('deleted_at')
            ->where('status', AssetStatus::VISIBLE)
            ->count();
    }

    protected function hasLowActivity(Brand $brand): bool
    {
        if ($this->getTotalVisibleAssetsCount($brand) === 0) {
            return false;
        }

        $cutoff = now()->subDays(7);
        $hasUpload = ActivityEvent::where('tenant_id', $brand->tenant_id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->whereIn('event_type', $this->uploadActivityEventTypes())
            ->where('created_at', '>=', $cutoff)
            ->exists();

        return ! $hasUpload;
    }
}
