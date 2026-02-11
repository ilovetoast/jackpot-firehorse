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

        $this->applyCreatedSort($query, $direction);
    }

    /**
     * Featured: starred assets first, then non-starred. Within each group, created_at (asc/desc).
     * We store strict boolean in assets.metadata.starred (see docs/STARRED_CANONICAL.md).
     */
    private function applyFeaturedSort(Builder $query, string $direction, string $driver): void
    {
        if ($driver === 'pgsql') {
            $query->orderByRaw(
                "CASE WHEN (metadata->>'starred')::text IN ('true', '1') THEN 1 ELSE 0 END DESC"
            );
        } else {
            $query->orderByRaw(
                "CASE WHEN JSON_EXTRACT(metadata, '$.starred') = true OR JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.starred')) IN ('true', '1') THEN 1 ELSE 0 END DESC"
            );
        }
        $query->orderBy('created_at', $direction);
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
        $allowed = [self::SORT_FEATURED, self::SORT_CREATED, self::SORT_QUALITY, self::SORT_MODIFIED, self::SORT_ALPHABETICAL];
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
