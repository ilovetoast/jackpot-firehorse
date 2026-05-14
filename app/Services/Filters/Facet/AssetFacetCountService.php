<?php

namespace App\Services\Filters\Facet;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;

/**
 * Phase 5 seam.
 *
 * Thin caller-facing wrapper around {@see FacetCountProvider}. Today it just
 * delegates; Phase 5 will give it caching, batched queries, and back-pressure.
 *
 * Splitting the service from the provider lets us:
 *   1. Swap the provider per-environment (e.g. NullFacetCountProvider in tests
 *      and a Postgres/OpenSearch-backed implementation in prod) without
 *      changing call sites.
 *   2. Layer caching policy on top of the provider in one place.
 *   3. Add fan-out/fan-in batching once Phase 5 needs it.
 *
 * Phase 2 callers can already compose against this service; their behavior
 * is identical until the provider gets swapped.
 */
class AssetFacetCountService
{
    public function __construct(
        protected FacetCountProvider $provider,
    ) {}

    public function estimateDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int {
        return $this->provider->estimateDistinctValueCount($filter, $tenant, $brand, $folder);
    }

    /**
     * @param  array<string, array{operator: string, value: mixed}>|null  $activeFilters
     * @return array<string, int>|null
     */
    public function countOptionsForFolder(
        MetadataField $filter,
        Tenant $tenant,
        ?Brand $brand,
        Category $folder,
        ?array $activeFilters = null,
    ): ?array {
        return $this->provider->countOptionsForFolder(
            $filter,
            $tenant,
            $brand,
            $folder,
            $activeFilters,
        );
    }
}
