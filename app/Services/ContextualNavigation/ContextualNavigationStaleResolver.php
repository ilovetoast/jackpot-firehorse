<?php

namespace App\Services\ContextualNavigation;

use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataFieldVisibility;
use App\Models\Tenant;

/**
 * Phase 6 — moves recommendations that no longer make sense to status='stale'.
 *
 * Five trigger conditions:
 *   1. The folder has been deleted (FK cascade handles this; not our job).
 *   2. The metadata field has been deleted (also FK cascade).
 *   3. The current quick-filter state ALREADY matches the recommendation
 *      (e.g., admin already enabled / pinned / disabled it manually).
 *   4. The recommendation is older than `recommendation_ttl_days` AND has
 *      not been seen by a recent recommender run.
 *   5. The recommender run did NOT re-emit this row (last_seen_at is older
 *      than the most recent run's timestamp). Handled implicitly by 4 +
 *      the recommender refreshing last_seen_at on every run.
 *
 * Approved/rejected rows are NEVER touched.
 */
class ContextualNavigationStaleResolver
{
    public function resolveForTenant(Tenant $tenant): int
    {
        $ttl = (int) config('contextual_navigation_insights.recommendation_ttl_days', 30);
        $cutoff = now()->subDays(max(1, $ttl));
        $marked = 0;

        $marked += $this->markAlreadyApplied($tenant);
        $marked += $this->markTtlExpired($tenant, $cutoff);

        return $marked;
    }

    /**
     * Stage A: state already matches recommendation.
     *
     * For each pending/deferred recommendation we look at the current
     * `metadata_field_visibility` row for (folder, field) and decide
     * whether the recommendation is now redundant.
     */
    private function markAlreadyApplied(Tenant $tenant): int
    {
        $rows = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                ContextualNavigationRecommendation::STATUS_PENDING,
                ContextualNavigationRecommendation::STATUS_DEFERRED,
            ])
            ->whereNotNull('category_id')
            ->whereNotNull('metadata_field_id')
            ->whereIn('recommendation_type', [
                ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
                ContextualNavigationRecommendation::TYPE_SUGGEST_PIN,
                ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN,
                ContextualNavigationRecommendation::TYPE_SUGGEST_DISABLE,
                ContextualNavigationRecommendation::TYPE_SUGGEST_OVERFLOW,
            ])
            ->get(['id', 'category_id', 'metadata_field_id', 'recommendation_type']);

        if ($rows->isEmpty()) return 0;

        // Batch-load every relevant visibility row in ONE query and index by
        // composite key. Previous implementation issued one `first()` per
        // (category_id, metadata_field_id) pair which scaled with the size
        // of the pending queue — see consolidation notes Phase 6.1.
        $categoryIds = $rows->pluck('category_id')->unique()->all();
        $fieldIds = $rows->pluck('metadata_field_id')->unique()->all();
        $byKey = [];
        if ($categoryIds !== [] && $fieldIds !== []) {
            $visRows = MetadataFieldVisibility::query()
                ->whereIn('category_id', $categoryIds)
                ->whereIn('metadata_field_id', $fieldIds)
                ->get();
            foreach ($visRows as $vis) {
                $byKey["{$vis->category_id}:{$vis->metadata_field_id}"] = $vis;
            }
        }

        $marked = 0;
        foreach ($rows as $rec) {
            $key = "{$rec->category_id}:{$rec->metadata_field_id}";
            $vis = $byKey[$key] ?? null;
            $isQuickFilter = $vis !== null && (bool) $vis->show_in_folder_quick_filters;
            $isPinned = $vis !== null && (bool) $vis->is_pinned_folder_quick_filter;

            $alreadyApplied = match ($rec->recommendation_type) {
                ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER => $isQuickFilter,
                ContextualNavigationRecommendation::TYPE_SUGGEST_PIN => $isPinned,
                ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN => ! $isPinned,
                ContextualNavigationRecommendation::TYPE_SUGGEST_DISABLE => ! $isQuickFilter,
                // Overflow has no persistent state; never auto-stale.
                default => false,
            };
            if ($alreadyApplied) {
                ContextualNavigationRecommendation::query()
                    ->where('id', $rec->id)
                    ->update([
                        'status' => ContextualNavigationRecommendation::STATUS_STALE,
                        'updated_at' => now(),
                    ]);
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * Stage B: TTL — pending/deferred rows whose last_seen_at is older
     * than the cutoff. The recommender refreshes last_seen_at on every
     * run, so any row that hasn't been re-emitted is by definition
     * unsupported by current data.
     */
    private function markTtlExpired(Tenant $tenant, \Illuminate\Support\Carbon $cutoff): int
    {
        return (int) ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                ContextualNavigationRecommendation::STATUS_PENDING,
                ContextualNavigationRecommendation::STATUS_DEFERRED,
            ])
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff);
            })
            ->update([
                'status' => ContextualNavigationRecommendation::STATUS_STALE,
                'updated_at' => now(),
            ]);
    }
}
