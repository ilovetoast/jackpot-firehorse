<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Phase L: Centralized asset sort logic.
 *
 * Single place for ORDER BY so sort behavior is deterministic and consistent
 * across Assets, Deliverables, and Collections. Composes after search and filters.
 *
 * Locked sort options (order in UI): featured | created | quality | modified | alphabetical
 * - Featured: starred assets first, then non-starred; secondary sort created_at (asc/desc).
 * - Created: created_at (asc = oldest first, desc = newest first).
 * - Quality: user quality rating 1–4 stars; direction applies (asc = lowest first, desc = highest first); then created_at.
 * - Modified: updated_at (asc = oldest first, desc = newest first).
 * - Alphabetical: title (fallback original_filename), asc = A–Z, desc = Z–A.
 */
class AssetSortService
{
    public const SORT_FEATURED = 'featured';
    public const SORT_CREATED = 'created';
    public const SORT_QUALITY = 'quality';
    public const SORT_MODIFIED = 'modified';
    public const SORT_ALPHABETICAL = 'alphabetical';
    public const SORT_COMPLIANCE_HIGH = 'compliance_high';
    public const SORT_COMPLIANCE_LOW = 'compliance_low';

    /** @deprecated Use SORT_FEATURED; normalized from request for backward compatibility */
    public const SORT_STARRED = 'starred';

    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';

    /** Default sort when none specified. Use everywhere (controllers, UI, reset) so they never drift. */
    public const DEFAULT_SORT = self::SORT_FEATURED;
    public const DEFAULT_DIRECTION = self::DIRECTION_DESC;

    /**
     * Apply sort to the asset query. Call after scoping, search, and filters; before pagination.
     *
     * @param Builder $query Asset query (assets table; metadata JSON column)
     * @param string $sort One of: featured, created, quality, modified, alphabetical (starred normalized to featured)
     * @param string $sortDirection asc | desc; applies to primary sort for created/modified, secondary for featured/quality
     */
    public function applySort(Builder $query, string $sort, string $sortDirection = self::DIRECTION_DESC): void
    {
        $direction = strtolower($sortDirection) === self::DIRECTION_ASC ? self::DIRECTION_ASC : self::DIRECTION_DESC;
        $driver = $query->getConnection()->getDriverName();

        if ($sort === self::SORT_FEATURED) {
            $this->applyFeaturedSort($query, $direction, $driver);
            return;
        }

        if ($sort === self::SORT_QUALITY) {
            $this->applyQualitySort($query, $direction, $driver);
            return;
        }

        if ($sort === self::SORT_MODIFIED) {
            $this->applyModifiedSort($query, $direction);
            return;
        }

        if ($sort === self::SORT_ALPHABETICAL) {
            $this->applyAlphabeticalSort($query, $direction);
            return;
        }

        if ($sort === self::SORT_COMPLIANCE_HIGH) {
            $this->applyComplianceSort($query, self::DIRECTION_DESC);
            return;
        }

        if ($sort === self::SORT_COMPLIANCE_LOW) {
            $this->applyComplianceSort($query, self::DIRECTION_ASC);
            return;
        }

        $this->applyCreatedSort($query, $direction);
    }

    /**
     * Compliance: sort by brand_compliance_scores.overall_score. Requires left join.
     * Skips join if already present (e.g. from compliance filter scope).
     */
    private function applyComplianceSort(Builder $query, string $direction): void
    {
        $joins = $query->getQuery()->joins ?? [];
        $hasBcs = collect($joins)->contains(fn ($j) => ($j->table ?? '') === 'brand_compliance_scores');
        if (!$hasBcs) {
            $query->leftJoin('brand_compliance_scores', function ($join) {
                $join->on('assets.id', '=', 'brand_compliance_scores.asset_id')
                    ->whereColumn('assets.brand_id', 'brand_compliance_scores.brand_id');
            });
        }
        $query->orderByRaw('brand_compliance_scores.overall_score IS NULL ' . ($direction === self::DIRECTION_DESC ? 'ASC' : 'DESC'));
        $query->orderBy('brand_compliance_scores.overall_score', $direction);
        $query->orderBy('assets.created_at', $direction);
    }

    /**
     * Featured: starred assets first, then non-starred. Within each group, created_at (asc/desc).
     * Uses assets.metadata.starred when present; falls back to latest asset_metadata value for
     * "starred" so sort matches display (assets with starred only in asset_metadata still sort first).
     */
    private function applyFeaturedSort(Builder $query, string $direction, string $driver): void
    {
        $starredExpr = $this->featuredStarredExpression($driver);
        $query->orderByRaw($starredExpr);
        $query->orderBy('created_at', $direction);
    }

    /**
     * SQL expression: 1 if asset is starred (metadata or asset_metadata), 0 otherwise.
     */
    private function featuredStarredExpression(string $driver): string
    {
        $joinWhere = 'am.asset_id = assets.id AND mf.id = am.metadata_field_id AND mf.key = \'starred\' AND (mf.tenant_id IS NULL OR mf.tenant_id = assets.tenant_id)';
        $order = 'ORDER BY mf.tenant_id IS NULL ASC, am.approved_at IS NULL ASC, am.id DESC LIMIT 1';

        if ($driver === 'pgsql') {
            $fromMeta = "(metadata->>'starred')::text IN ('true', '1', 'yes')";
            $fallback = "(SELECT CASE WHEN (am.value_json)::text IN ('true', 't', '1') OR LOWER(TRIM((am.value_json)::text, '\"')) IN ('true', '1', 'yes') THEN 1 ELSE 0 END FROM asset_metadata am INNER JOIN metadata_fields mf ON {$joinWhere} {$order})";
            return "CASE WHEN {$fromMeta} OR ({$fallback}) = 1 THEN 1 ELSE 0 END DESC";
        }

        $fromMeta = "JSON_EXTRACT(assets.metadata, '$.starred') = true OR JSON_UNQUOTE(JSON_EXTRACT(assets.metadata, '$.starred')) IN ('true', '1', 'yes')";
        $fallback = "(SELECT CASE WHEN JSON_UNQUOTE(am.value_json) IN ('true', '1', 'yes') THEN 1 ELSE 0 END FROM asset_metadata am INNER JOIN metadata_fields mf ON {$joinWhere} {$order})";
        return "CASE WHEN {$fromMeta} OR {$fallback} = 1 THEN 1 ELSE 0 END DESC";
    }

    /**
     * Created: newest first (or oldest first if asc).
     */
    private function applyCreatedSort(Builder $query, string $direction): void
    {
        $query->orderBy('created_at', $direction);
    }

    /**
     * Quality: sort by user quality rating (1–4 stars). Direction applies: asc = lowest first, desc = highest first.
     * Assets without rating last. Ties broken by created_at.
     */
    private function applyQualitySort(Builder $query, string $direction, string $driver): void
    {
        $nullsOrder = $direction === self::DIRECTION_DESC ? 'ASC' : 'DESC'; // nulls last for both
        $ratingOrder = $direction === self::DIRECTION_DESC ? 'DESC' : 'ASC';
        if ($driver === 'pgsql') {
            $query->orderByRaw("(metadata->>'quality_rating') IS NULL {$nullsOrder}");
            $query->orderByRaw("CAST(NULLIF(metadata->>'quality_rating', '') AS INTEGER) {$ratingOrder} NULLS LAST");
        } else {
            $query->orderByRaw("(JSON_EXTRACT(metadata, '$.quality_rating') IS NULL) {$nullsOrder}");
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.quality_rating')) AS UNSIGNED) {$ratingOrder}");
        }
        $query->orderBy('created_at', $direction);
    }

    /**
     * Modified: sort by updated_at (asc = oldest first, desc = newest first).
     */
    private function applyModifiedSort(Builder $query, string $direction): void
    {
        $query->orderBy('updated_at', $direction);
    }

    /**
     * Alphabetical: sort by title (fallback to original_filename when title is null/empty). asc = A–Z, desc = Z–A.
     */
    private function applyAlphabeticalSort(Builder $query, string $direction): void
    {
        $query->orderByRaw("COALESCE(NULLIF(TRIM(title), ''), original_filename) {$direction}");
    }

    /**
     * Normalize sort param from request: must be one of the locked options. Legacy 'starred' → 'featured'.
     */
    public function normalizeSort(?string $sort): string
    {
        $allowed = [self::SORT_FEATURED, self::SORT_CREATED, self::SORT_QUALITY, self::SORT_MODIFIED, self::SORT_ALPHABETICAL, self::SORT_COMPLIANCE_HIGH, self::SORT_COMPLIANCE_LOW];
        $sort = $sort ? strtolower(trim($sort)) : '';
        if ($sort === self::SORT_STARRED) {
            return self::SORT_FEATURED;
        }
        return in_array($sort, $allowed, true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * Normalize sort_direction: asc or desc.
     */
    public function normalizeSortDirection(?string $sortDirection): string
    {
        $d = $sortDirection ? strtolower(trim($sortDirection)) : '';
        return $d === self::DIRECTION_ASC ? self::DIRECTION_ASC : self::DEFAULT_DIRECTION;
    }
}
