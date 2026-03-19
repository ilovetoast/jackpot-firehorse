<?php

namespace App\Services;

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
        protected MetadataAnalyticsService $metadataAnalytics
    ) {
    }

    /**
     * Get "What Needs Attention" signals for a brand.
     * Max 4 signals, sorted by priority (high → medium → low).
     *
     * @return array<array{type: string, priority: string, icon: string, label: string, href: string, permission?: string}>
     */
    public function getSignals(Brand $brand, User $user): array
    {
        $cacheKey = "brand:{$brand->id}:insights";

        $signals = Cache::remember($cacheKey, 300, function () use ($brand) {
            $signals = [];

            $tenant = $brand->tenant;
            if (!$tenant) {
                return [];
            }

            // 1a. AI Tag Suggestions Pending (high)
            $tagCount = $this->getPendingAiTagSuggestionsCount($brand);
            if ($tagCount > 0) {
                $signals[] = [
                    'type' => 'action',
                    'priority' => 'high',
                    'icon' => 'sparkles',
                    'label' => "{$tagCount} AI tag suggestions to review",
                    'href' => '/app/insights/review?tab=tags',
                    'permission' => 'canViewAnalytics',
                    'context' => [
                        'count' => $tagCount,
                        'category' => 'ai_tags',
                    ],
                ];
            }

            // 1b. AI Category Suggestions Pending (high)
            $categoryCount = $this->getPendingAiCategorySuggestionsCount($brand);
            if ($categoryCount > 0) {
                $signals[] = [
                    'type' => 'action',
                    'priority' => 'high',
                    'icon' => 'sparkles',
                    'label' => "{$categoryCount} AI category suggestions to review",
                    'href' => '/app/insights/review?tab=categories',
                    'permission' => 'canViewAnalytics',
                    'context' => [
                        'count' => $categoryCount,
                        'category' => 'ai_categories',
                    ],
                ];
            }

            // 2. Missing Metadata (medium) — always link to asset grid (never analytics)
            $assetsMissingMetadata = $this->getAssetsMissingMetadataCount($brand);
            if ($assetsMissingMetadata > 0) {
                $firstAssetId = $this->getFirstAssetMissingMetadataId($brand);
                $href = $firstAssetId
                    ? '/app/assets?missing_metadata=1&asset=' . $firstAssetId
                    : '/app/assets?missing_metadata=1';
                $signals[] = [
                    'type' => 'action',
                    'priority' => 'medium',
                    'icon' => 'document',
                    'label' => "{$assetsMissingMetadata} assets missing metadata",
                    'href' => $href,
                    'permission' => 'canViewAnalytics',
                    'context' => [
                        'count' => $assetsMissingMetadata,
                        'category' => 'metadata',
                    ],
                ];
            }

            // 3. Expiring Assets (medium)
            $expiringCount = $this->getExpiringAssetsCount($brand);
            if ($expiringCount > 0) {
                $signals[] = [
                    'type' => 'action',
                    'priority' => 'medium',
                    'icon' => 'clock',
                    'label' => "{$expiringCount} assets expiring soon",
                    'href' => '/app/insights/overview?tab=rights',
                    'permission' => 'canViewAnalytics',
                    'context' => [
                        'count' => $expiringCount,
                        'category' => 'rights',
                    ],
                ];
            }

            // 4. Low Activity (low)
            if ($this->hasLowActivity($brand)) {
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

        // Filter by user permission on the fly (cache is shared)
        $tenant = $brand->tenant;
        return array_values(array_filter($signals, function ($s) use ($user, $tenant) {
            if (empty($s['permission'])) return true;
            if (!$tenant) return false;
            if ($s['permission'] === 'canViewAnalytics') {
                return $user->hasPermissionForTenant($tenant, 'activity_logs.view');
            }
            return true;
        }));
    }

    protected function getPendingAiTagSuggestionsCount(Brand $brand): int
    {
        return (int) DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at')
            ->count();
    }

    protected function getPendingAiCategorySuggestionsCount(Brand $brand): int
    {
        return (int) DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai')
            ->count();
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
        if (!$tenant) {
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
        if (!$tenant) {
            return ['sharedCount' => 0, 'uploadCount' => 0, 'aiCompleted' => 0, 'teamChanges' => 0];
        }

        $cutoff = now()->subDays(7);

        $uploadCount = ActivityEvent::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->where('event_type', EventType::ASSET_UPLOADED)
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

    protected function hasLowActivity(Brand $brand): bool
    {
        $cutoff = now()->subDays(7);
        $hasUpload = ActivityEvent::where('tenant_id', $brand->tenant_id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->where('event_type', EventType::ASSET_UPLOADED)
            ->where('created_at', '>=', $cutoff)
            ->exists();

        return !$hasUpload;
    }
}
