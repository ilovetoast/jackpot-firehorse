<?php

namespace App\Services\Filters\Facet;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 5 — caching decorator wrapping a real {@see FacetCountProvider}.
 *
 * Strategy:
 *   - Short TTL (default 30s). Counts in a DAM are not lifecycle-critical;
 *     the user opening a flyout repeatedly within the same session sees
 *     stable counts even as other tenants write.
 *   - Cache key is namespaced by tenant + brand + folder + field + a
 *     stable hash of the active-filter state. Two flyout opens with the
 *     same context return the same cached counts; toggling a sibling
 *     dimension naturally cache-misses because the hash changes.
 *   - No tag invalidation infrastructure. The TTL handles staleness.
 *     Brutal invalidation can be layered on later by clearing the
 *     `qf_facet:` namespace.
 *
 * Configurable via:
 *   - `categories.folder_quick_filters.facet_cache_ttl_seconds` (int, default 30)
 *   - `categories.folder_quick_filters.facet_cache_enabled` (bool, default true)
 *
 * The decorator is bound as the `FacetCountProvider` singleton in
 * AppServiceProvider; the underlying real provider is constructed and
 * injected via the container.
 */
class CachedFacetCountProvider implements FacetCountProvider
{
    public const KEY_PREFIX = 'qf_facet';

    private CacheRepository $cache;

    public function __construct(
        protected FacetCountProvider $inner,
        ?CacheRepository $cache = null,
    ) {
        $this->cache = $cache ?? Cache::store();
    }

    public function estimateDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int {
        if (! $this->cacheEnabled()) {
            return $this->inner->estimateDistinctValueCount($filter, $tenant, $brand, $folder);
        }

        $key = sprintf(
            '%s:est:t%s:b%s:fld%s:fid%d',
            self::KEY_PREFIX,
            $tenant?->id ?? '0',
            $brand?->id ?? '0',
            $folder?->id ?? '0',
            (int) $filter->id,
        );

        return $this->cache->remember(
            $key,
            $this->ttl(),
            fn () => $this->inner->estimateDistinctValueCount($filter, $tenant, $brand, $folder)
        );
    }

    public function countOptionsForFolder(
        MetadataField $filter,
        Tenant $tenant,
        ?Brand $brand,
        Category $folder,
        ?array $activeFilters = null,
    ): ?array {
        if (! $this->cacheEnabled()) {
            return $this->inner->countOptionsForFolder(
                $filter,
                $tenant,
                $brand,
                $folder,
                $activeFilters,
            );
        }

        $hash = $this->hashActiveFilters($activeFilters, (string) $filter->key);
        $key = sprintf(
            '%s:opts:t%d:b%s:fld%d:fid%d:%s',
            self::KEY_PREFIX,
            (int) $tenant->id,
            $brand?->id ?? '0',
            (int) $folder->id,
            (int) $filter->id,
            $hash,
        );

        return $this->cache->remember(
            $key,
            $this->ttl(),
            fn () => $this->inner->countOptionsForFolder(
                $filter,
                $tenant,
                $brand,
                $folder,
                $activeFilters,
            )
        );
    }

    /**
     * Build a deterministic, length-bounded hash of the active-filter
     * payload. The current dimension is removed BEFORE hashing so two
     * requests with different selections within the same dimension still
     * share a cache entry (counts don't depend on the current dimension's
     * own selections, by design).
     *
     * @param  array<string, array{operator: string, value: mixed}>|null  $activeFilters
     */
    private function hashActiveFilters(?array $activeFilters, string $excludeKey): string
    {
        if ($activeFilters === null || $activeFilters === []) {
            return 'none';
        }

        $normalized = [];
        foreach ($activeFilters as $key => $def) {
            $strKey = (string) $key;
            if ($strKey === $excludeKey) {
                continue;
            }
            if (! is_array($def) || ! array_key_exists('value', $def)) {
                continue;
            }
            $value = $def['value'];
            if (is_array($value)) {
                $value = array_map(static fn ($v) => (string) $v, $value);
                sort($value);
            }
            $normalized[$strKey] = [
                'op' => (string) ($def['operator'] ?? 'equals'),
                'v' => $value,
            ];
        }
        if ($normalized === []) {
            return 'none';
        }
        ksort($normalized);

        return substr(hash('xxh64', json_encode($normalized) ?: ''), 0, 16);
    }

    private function ttl(): int
    {
        $ttl = (int) config('categories.folder_quick_filters.facet_cache_ttl_seconds', 30);

        return max(1, $ttl);
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('categories.folder_quick_filters.facet_cache_enabled', true);
    }
}
