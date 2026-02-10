<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Phase L: Centralized asset sort logic.
 *
 * Single place for ORDER BY so sort behavior is deterministic and consistent
 * across Assets, Deliverables, and Collections. Composes after search and filters.
 *
 * Locked sort options: starred | created | quality
 * - Starred: starred assets first, then non-starred; secondary sort created_at (stable).
 * - Created: newest first (created_at).
 * - Quality: highest first; assets without rating last (no interleaving).
 */
class AssetSortService
{
    public const SORT_STARRED = 'starred';
    public const SORT_CREATED = 'created';
    public const SORT_QUALITY = 'quality';

    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';

    /** Default sort when none specified. Use everywhere (controllers, UI, reset) so they never drift. */
    public const DEFAULT_SORT = self::SORT_CREATED;
    public const DEFAULT_DIRECTION = self::DIRECTION_DESC;

    /**
     * Apply sort to the asset query. Call after scoping, search, and filters; before pagination.
     *
     * @param Builder $query Asset query (assets table; metadata JSON column)
     * @param string $sort One of: starred, created, quality
     * @param string $sortDirection asc | desc (for created; for starred/quality only affects secondary created_at)
     */
    public function applySort(Builder $query, string $sort, string $sortDirection = self::DIRECTION_DESC): void
    {
        $direction = strtolower($sortDirection) === self::DIRECTION_ASC ? self::DIRECTION_ASC : self::DIRECTION_DESC;
        $driver = $query->getConnection()->getDriverName();

        if ($sort === self::SORT_STARRED) {
            $this->applyStarredSort($query, $direction, $driver);
            return;
        }

        if ($sort === self::SORT_QUALITY) {
            $this->applyQualitySort($query, $direction, $driver);
            return;
        }

        $this->applyCreatedSort($query, $direction);
    }

    /**
     * Starred: starred assets first, then non-starred. Within each group, created_at for stability.
     * Starred = true (or 'true'/'1' in JSON); NULL/false = non-starred.
     */
    private function applyStarredSort(Builder $query, string $direction, string $driver): void
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
     * Quality: rated assets first (highest first), then assets without rating last. Ties broken by created_at.
     * NULL quality_rating must not interleave with rated assets.
     */
    private function applyQualitySort(Builder $query, string $direction, string $driver): void
    {
        if ($driver === 'pgsql') {
            $query->orderByRaw("(metadata->>'quality_rating') IS NULL ASC");
            $query->orderByRaw("CAST(NULLIF(metadata->>'quality_rating', '') AS INTEGER) DESC NULLS LAST");
        } else {
            $query->orderByRaw("(JSON_EXTRACT(metadata, '$.quality_rating') IS NULL) ASC");
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.quality_rating')) AS UNSIGNED) DESC");
        }
        $query->orderBy('created_at', $direction);
    }

    /**
     * Normalize sort param from request: must be one of the locked options.
     */
    public function normalizeSort(?string $sort): string
    {
        $allowed = [self::SORT_STARRED, self::SORT_CREATED, self::SORT_QUALITY];
        $sort = $sort ? strtolower(trim($sort)) : '';
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
