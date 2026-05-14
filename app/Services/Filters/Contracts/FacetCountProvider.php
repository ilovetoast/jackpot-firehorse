<?php

namespace App\Services\Filters\Contracts;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;

/**
 * Phase 5 seam: returns distinct-value cardinality for a metadata filter,
 * scoped to whatever subset of (tenant, brand, category) the caller cares
 * about. Phase 2 binds {@see \App\Services\Filters\Facet\NullFacetCountProvider}
 * — every method returns null. Phase 5 will swap in a counting implementation
 * (cached aggregation queries, materialized facet table, search-engine facets,
 * …) without any caller having to change.
 *
 * Design rules, on purpose:
 *   - Methods MUST be cheap. Implementations may aggregate from a cache or
 *     return null when the answer is unknown — they MUST NOT block on a
 *     synchronous fan-out query in a request lifecycle.
 *   - null means "unknown", not "zero". Callers should treat null as
 *     "I don't know yet — fall back to permissive defaults".
 *   - Implementations are responsible for tenant isolation. Never accept
 *     `null` $tenant from a request-scoped caller.
 */
interface FacetCountProvider
{
    /**
     * Estimated distinct value count for a filter, optionally scoped.
     *
     * @param  Tenant|null  $tenant Required for any non-system query in Phase 5+.
     *                              Null is allowed only for the unscoped
     *                              system-default count (rare, mostly tests).
     * @param  Brand|null   $brand  Narrow to a single brand inside $tenant.
     * @param  Category|null $folder Narrow to a single folder inside $tenant.
     * @return int|null              null = unknown / not yet counted.
     */
    public function estimateDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int;

    /**
     * Per-(option) counts for a filter for a folder. Used by Phase 5's value
     * flyout. Returns null when unknown so callers can degrade gracefully.
     *
     * Result, when non-null, is keyed by option value (the
     * `metadata_options.value` string for system fields, or the canonical
     * stored value for tenant fields) and maps to the count of matching
     * assets in the folder.
     *
     * Phase 5: `$activeFilters` lets callers pass the rest of the active
     * filter state for the page. Implementations MUST exclude the field
     * being counted from the active filter set before applying — when
     * editing Environment, the count for "Nature" should not collapse to
     * "assets where environment=Nature AND environment=<currently selected>".
     * Other dimensions remain constraints.
     *
     * @param  array<string, array{operator: string, value: mixed}>|null  $activeFilters
     *         Keyed by metadata field key. Same shape used by URL parsing
     *         (`parseFiltersFromUrl` / `MetadataFilterService::applyFilters`).
     * @return array<string, int>|null
     */
    public function countOptionsForFolder(
        MetadataField $filter,
        Tenant $tenant,
        ?Brand $brand,
        Category $folder,
        ?array $activeFilters = null,
    ): ?array;
}
