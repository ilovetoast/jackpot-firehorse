<?php

namespace App\Services\Filters\Facet;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use RuntimeException;

/**
 * Phase 5 seam (NOT IMPLEMENTED).
 *
 * Reserved future home for cross-filter rollups: "give me the active value
 * counts for ALL quick filters in folder X in one round-trip". Splitting this
 * out from {@see AssetFacetCountService} makes room for query patterns that
 * are different in shape (one query, many filters) from a single-filter count.
 *
 * This Phase 2 stub exists only so future code can depend-on / type-hint
 * against a stable class. Calling any non-trivial method throws to make a
 * premature wire-up loud rather than silently returning empty results.
 */
class FilterFacetAggregationService
{
    public function aggregateForFolder(
        Tenant $tenant,
        Category $folder,
        /** @var list<MetadataField> $filters */
        array $filters,
    ): array {
        $this->notYetImplemented(__METHOD__);
    }

    private function notYetImplemented(string $method): never
    {
        throw new RuntimeException(
            "{$method} is a Phase 5 extension point. Implement before invoking."
        );
    }
}
