<?php

namespace App\Services\Filters\Facet;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;

/**
 * Phase 2 default {@see FacetCountProvider}.
 *
 * Returns null for every query, signalling "unknown — count not yet implemented".
 * Bound as the default implementation in {@see \App\Providers\AppServiceProvider}.
 *
 * Phase 5 swaps this for a real counting implementation; no other code changes.
 */
class NullFacetCountProvider implements FacetCountProvider
{
    public function estimateDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int {
        return null;
    }

    public function countOptionsForFolder(
        MetadataField $filter,
        Tenant $tenant,
        ?Brand $brand,
        Category $folder,
        ?array $activeFilters = null,
    ): ?array {
        return null;
    }
}
