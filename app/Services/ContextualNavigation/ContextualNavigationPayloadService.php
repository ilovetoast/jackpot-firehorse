<?php

namespace App\Services\ContextualNavigation;

use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\Tenant;

/**
 * Phase 6 — read-side helpers for surfacing recommendations into existing
 * Inertia payloads.
 *
 * Used by:
 *   - AiReviewController     (already counts pending inline)
 *   - AnalyticsOverviewController (top-priority cards)
 *   - TenantMetadataRegistryController / FolderSchemaHelp (per-field hints)
 *
 * Goals:
 *   - Read-only, side-effect-free.
 *   - Tight queries (LIMIT + index-friendly WHERE).
 *   - Stable shape so frontend code can rely on it.
 */
class ContextualNavigationPayloadService
{
    /**
     * Tenant-level summary for the Overview page. Returns up to N
     * "highest-priority" recommendation cards.
     *
     * @return array{
     *     total_pending: int,
     *     by_type: array<string, int>,
     *     top: list<array<string, mixed>>
     * }
     */
    public function overviewSummary(Tenant $tenant, int $topLimit = 4): array
    {
        $totalPending = (int) ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->count();

        $byType = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->selectRaw('recommendation_type, COUNT(*) as c')
            ->groupBy('recommendation_type')
            ->pluck('c', 'recommendation_type')
            ->map(fn ($v) => (int) $v)
            ->all();

        $rows = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->orderByDesc('score')
            ->orderByDesc('updated_at')
            ->limit(max(1, $topLimit))
            ->get();

        $folders = $rows->pluck('category_id')->filter()->unique();
        $fields = $rows->pluck('metadata_field_id')->filter()->unique();
        $folderMap = $folders->isEmpty()
            ? collect()
            : Category::query()->whereIn('id', $folders)->get(['id', 'name', 'slug'])->keyBy('id');
        $fieldMap = $fields->isEmpty()
            ? collect()
            : MetadataField::query()->whereIn('id', $fields)->get(['id', 'key', 'system_label'])->keyBy('id');

        $top = $rows->map(function (ContextualNavigationRecommendation $r) use ($folderMap, $fieldMap) {
            $folder = $r->category_id ? $folderMap->get($r->category_id) : null;
            $field = $r->metadata_field_id ? $fieldMap->get($r->metadata_field_id) : null;

            return [
                'id' => (int) $r->id,
                'recommendation_type' => $r->recommendation_type,
                'score' => $r->score !== null ? (float) $r->score : null,
                'reason_summary' => $r->reason_summary,
                'is_actionable' => $r->isActionable(),
                'folder_name' => $folder?->name,
                'folder_slug' => $folder?->slug,
                'field_key' => $field?->key,
                'field_label' => $field?->system_label ?? $field?->key,
            ];
        })->values()->all();

        return [
            'total_pending' => $totalPending,
            'by_type' => $byType,
            'top' => $top,
        ];
    }

    /**
     * Hints keyed by metadata_field_id, scoped to one (tenant, folder).
     * Used by the tenant metadata registry payload so the manage UI can
     * render hints for every visible field in one batched query.
     *
     * @param  int[]  $fieldIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function hintsForFolderFields(int $tenantId, int $folderId, array $fieldIds): array
    {
        if ($fieldIds === []) return [];

        $rows = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenantId)
            ->where('category_id', $folderId)
            ->whereIn('metadata_field_id', $fieldIds)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->orderByDesc('score')
            ->get(['id', 'recommendation_type', 'score', 'reason_summary', 'source', 'metadata_field_id']);

        $out = [];
        foreach ($rows as $r) {
            $fid = (int) $r->metadata_field_id;
            if (! isset($out[$fid])) {
                $out[$fid] = [];
            }
            if (count($out[$fid]) >= 3) continue; // cap per field
            $out[$fid][] = [
                'id' => (int) $r->id,
                'recommendation_type' => $r->recommendation_type,
                'score' => $r->score !== null ? (float) $r->score : null,
                'reason_summary' => $r->reason_summary,
                'source' => $r->source,
            ];
        }

        return $out;
    }
}
