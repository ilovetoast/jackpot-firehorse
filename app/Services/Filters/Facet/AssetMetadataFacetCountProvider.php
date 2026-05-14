<?php

namespace App\Services\Filters\Facet;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\MetadataOption;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 — real {@see FacetCountProvider} implementation backed by the
 * existing asset/asset_metadata schema and the canonical
 * {@see MetadataFilterService}. Replaces {@see NullFacetCountProvider} as
 * the bound implementation in production.
 *
 * Counting strategy (deliberately conservative for Phase 5):
 *   1. Build the base `Asset` query using the same scopes/clauses as
 *      `AssetController::index` (tenant + brand + category + asset-library
 *      types + non-staged + non-trashed). The result is "all candidate
 *      assets for this folder, ignoring metadata filters".
 *   2. Apply ALL active filters EXCEPT the field being counted, using
 *      `MetadataFilterService`. This ensures Nature's count under
 *      Environment is "matches every other dimension currently selected,
 *      ignoring environment", which is the standard facet semantic.
 *   3. For each candidate value (`metadata_options` rows up to the
 *      configured visible-values limit), clone the base query, apply the
 *      current field with that single value as an additional filter,
 *      and `count()`.
 *
 * Why per-value queries:
 *   - Bounded by `max_visible_values_per_filter` (default 20).
 *   - Each query reuses the existing correlated-EXISTS pattern in
 *     `MetadataFilterService` — no duplicate filter code.
 *   - Cleanly handles single_select, multi_select (`JSON_CONTAINS`), and
 *     boolean without unnesting JSON arrays in SQL.
 *   - Caching wraps the whole call (see {@see CachedFacetCountProvider}),
 *     so the typical request hits 0 queries.
 *
 * Phase 6 candidates: switch to a single grouped query with `JSON_TABLE`
 * for multi-select; introduce a materialized facet table; integrate
 * search-engine facets.
 */
class AssetMetadataFacetCountProvider implements FacetCountProvider
{
    /**
     * Cap defensively even if a caller bypasses the value endpoint's
     * configured visible-values limit. Counting more than this in one
     * request becomes a performance liability without a materialized
     * facet store.
     */
    private const MAX_VALUES_PER_QUERY = 50;

    public function __construct(
        protected MetadataFilterService $metadataFilters,
        protected MetadataSchemaResolver $schemaResolver,
    ) {}

    public function estimateDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int {
        // Phase 5: cheap distinct-value estimate. We only have the option
        // table to consult cheaply; that's the upper bound on what the user
        // could ever see in the flyout. Returning the option count is fine
        // for telemetry/admin purposes — the per-folder real distinct count
        // is exposed via countOptionsForFolder() instead.
        return MetadataOption::query()
            ->where('metadata_field_id', $filter->id)
            ->count();
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
        $candidateValues = $this->candidateValues($filter);
        if ($candidateValues === []) {
            return [];
        }

        try {
            $baseQuery = $this->buildBaseQuery($tenant, $brand, $folder);
        } catch (\Throwable $e) {
            Log::warning('AssetMetadataFacetCountProvider: failed to build base query', [
                'filter_id' => $filter->id,
                'folder_id' => $folder->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Resolve the schema once. Used for active-filter exclusion AND
        // value-application below.
        $schema = $this->resolveSchema($tenant, $brand, $folder);

        // Apply OTHER active filters (everything except the field we're
        // counting). MetadataFilterService::applyFilters mutates the query
        // in place — it's safe because we clone for each per-value count.
        $filtersWithoutCurrent = $this->stripCurrentField($activeFilters, (string) $filter->key);
        if ($filtersWithoutCurrent !== []) {
            $this->metadataFilters->applyFilters($baseQuery, $filtersWithoutCurrent, $schema);
        }

        $counts = [];
        foreach ($candidateValues as $value) {
            $counts[(string) $value] = $this->countForValue(
                $baseQuery,
                $filter,
                $value,
                $schema,
            );
        }

        return $counts;
    }

    private function buildBaseQuery(Tenant $tenant, ?Brand $brand, Category $folder): Builder
    {
        $query = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->forAssetLibraryTypes()
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $folder->id]
            );

        if ($brand instanceof Brand) {
            $query->where('brand_id', $brand->id);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function candidateValues(MetadataField $filter): array
    {
        $type = $this->canonicalType($filter);
        if ($type === 'boolean') {
            return ['true', 'false'];
        }

        return MetadataOption::query()
            ->where('metadata_field_id', $filter->id)
            ->orderBy('system_label')
            ->limit(self::MAX_VALUES_PER_QUERY)
            ->pluck('value')
            ->map(static fn ($v) => (string) $v)
            ->all();
    }

    private function canonicalType(MetadataField $filter): string
    {
        $type = strtolower((string) $filter->type);

        return match ($type) {
            'multi_select', 'multiselect' => 'multiselect',
            'single_select', 'select' => 'select',
            'boolean', 'bool' => 'boolean',
            default => $type,
        };
    }

    /**
     * Count assets in the base query that ALSO match
     * `<filter.key> equals <value>`. Reuses the canonical filter machinery
     * so we never diverge from how the asset grid filters by the same
     * value.
     */
    private function countForValue(
        Builder $baseQuery,
        MetadataField $filter,
        string $value,
        array $schema,
    ): int {
        $cloned = (clone $baseQuery);
        $key = (string) ($filter->key ?? '');
        if ($key === '') {
            return 0;
        }
        $singleFilter = [
            $key => [
                'operator' => 'equals',
                'value' => $value,
            ],
        ];
        $this->metadataFilters->applyFilters($cloned, $singleFilter, $schema);

        return (int) $cloned->count();
    }

    private function resolveSchema(Tenant $tenant, ?Brand $brand, Category $folder): array
    {
        // Phase 5: facet counting uses the same schema as listing. The schema
        // resolver requires a concrete asset_type bucket (image/video/document);
        // 'image' is the broadest and matches the metadata layout used by the
        // sidebar's quick filter list. If schema resolution fails for exotic
        // categories, log and return an empty schema so applyFilters becomes
        // a no-op (counts default to "all values match").
        try {
            return $this->schemaResolver->resolve(
                (int) $tenant->id,
                $brand?->id ?? null,
                (int) $folder->id,
                'image',
            );
        } catch (\Throwable $e) {
            Log::debug('AssetMetadataFacetCountProvider: schema resolution failed; using empty schema', [
                'tenant_id' => $tenant->id,
                'folder_id' => $folder->id,
                'error' => $e->getMessage(),
            ]);

            return ['fields' => []];
        }
    }

    /**
     * @param  array<string, array{operator: string, value: mixed}>|null  $activeFilters
     * @return array<string, array{operator: string, value: mixed}>
     */
    private function stripCurrentField(?array $activeFilters, string $currentKey): array
    {
        if ($activeFilters === null || $activeFilters === []) {
            return [];
        }

        $out = [];
        foreach ($activeFilters as $key => $def) {
            if ((string) $key === $currentKey) {
                continue;
            }
            if (! is_array($def) || ! array_key_exists('value', $def)) {
                continue;
            }
            $out[(string) $key] = $def;
        }

        return $out;
    }
}
