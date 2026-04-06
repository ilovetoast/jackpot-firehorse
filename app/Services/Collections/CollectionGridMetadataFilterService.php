<?php

namespace App\Services\Collections;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\AiMetadataConfidenceService;
use App\Services\Color\HueClusterService;
use App\Services\Metadata\MetadataValueNormalizer;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Builds metadata filter schema, URL-synced filter state, and available_values for the Collections asset grid,
 * aligned with {@see \App\Http\Controllers\AssetController::index()} but scoped to a single collection.
 * Omits the "collection" filter (redundant inside one collection).
 */
class CollectionGridMetadataFilterService
{
    public function __construct(
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected AiMetadataConfidenceService $confidenceService,
    ) {}

    public function resolveSchemaFileType(string $collectionType): string
    {
        return $collectionType === 'deliverable' ? 'document' : 'image';
    }

    public function resolveSchema(Tenant $tenant, Brand $brand, ?int $categoryId, string $fileType): array
    {
        return $this->metadataSchemaResolver->resolve(
            $tenant->id,
            $brand->id,
            $categoryId,
            $fileType
        );
    }

    /**
     * Parse metadata filters from JSON `filters` or flat query params (same rules as AssetController, collection-safe reserved keys).
     *
     * @return array<string, array{operator: string, value: mixed}>
     */
    public function parseFiltersFromRequest(Request $request, array $schema): array
    {
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?? [];
        }

        if (empty($filters) || ! is_array($filters)) {
            $filterKeys = array_values(array_filter(array_column($schema['fields'] ?? [], 'key')));
            $specialFilterKeys = ['tags', 'dominant_hue_group'];
            $filterKeys = array_values(array_unique(array_merge($filterKeys, $specialFilterKeys)));
            $reserved = [
                'category', 'sort', 'sort_direction', 'lifecycle', 'uploaded_by', 'file_type', 'asset',
                'edit_metadata', 'page', 'filters', 'q', 'collection', 'collection_type', 'category_id',
                'load_more', 'group_by_category',
            ];
            $filters = [];
            $multiValueKeys = ['tags', 'dominant_hue_group'];
            foreach ($filterKeys as $key) {
                if (in_array($key, $reserved, true)) {
                    continue;
                }
                $val = $request->input($key);
                if ($val !== null && $val !== '') {
                    if (in_array($key, $multiValueKeys, true) && is_array($val)) {
                        $val = array_values(array_unique(array_map('strval', array_filter($val))));
                    }
                    $filters[$key] = ['operator' => 'equals', 'value' => $val];
                }
            }
        }

        unset($filters['collection']);

        return is_array($filters) ? $filters : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function excludeCollectionFieldFromSchema(array $filterableSchema): array
    {
        return array_values(array_filter(
            $filterableSchema,
            fn ($f) => ($f['field_key'] ?? $f['key'] ?? '') !== 'collection'
        ));
    }

    /**
     * @return array<string, int>
     */
    public function buildHueClusterCounts(Builder $queryAfterFiltersSearchAndSort): array
    {
        $hueClusterCounts = [];
        $hueCountQuery = (clone $queryAfterFiltersSearchAndSort)
            ->select('assets.dominant_hue_group', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('assets.dominant_hue_group')
            ->where('assets.dominant_hue_group', '!=', '')
            ->groupBy('assets.dominant_hue_group')
            ->reorder();
        foreach ($hueCountQuery->get() as $row) {
            $hueClusterCounts[(string) $row->dominant_hue_group] = (int) $row->cnt;
        }

        return $hueClusterCounts;
    }

    /**
     * @param  EloquentCollection<int, \App\Models\Asset>|array<int, \App\Models\Asset>  $assetModelsFirstPage
     * @param  array<int, array<string, mixed>>  $mappedAssets
     * @return array{filterable_schema: array<int, array<string, mixed>>, available_values: array<string, array<int, mixed>>}
     */
    public function buildFilterableSchemaAndAvailableValues(
        array $schema,
        ?Category $category,
        Tenant $tenant,
        Builder $baseQueryForFilterVisibility,
        array $hueClusterCounts,
        EloquentCollection|array $assetModelsFirstPage,
        array $mappedAssets,
    ): array {
        $filterableSchema = $this->metadataFilterService->getFilterableFields($schema, $category, $tenant);
        $filterableSchema = $this->excludeCollectionFieldFromSchema($filterableSchema);

        if (! empty($filterableSchema)) {
            $keysWithValues = $this->metadataFilterService->getFieldKeysWithValuesInScope($baseQueryForFilterVisibility, $filterableSchema);
            $filterableSchema = $this->metadataFilterService->restrictFilterableSchemaToKeysWithValuesInScope($filterableSchema, $keysWithValues);
        }

        $availableValues = [];
        $assets = collect($mappedAssets);

        $assetModelsList = $assetModelsFirstPage instanceof EloquentCollection
            ? $assetModelsFirstPage->all()
            : $assetModelsFirstPage;
        $assetIdsForAvailableValues = array_map(
            fn ($a) => $a->id,
            $assetModelsList
        );

        if (! empty($filterableSchema) && count($assetIdsForAvailableValues) > 0) {
            $assetIds = $assetIdsForAvailableValues;
            $filterableFieldKeys = [];
            foreach ($filterableSchema as $field) {
                $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
                if ($fieldKey) {
                    $filterableFieldKeys[$fieldKey] = true;
                }
            }

            if (! empty($filterableFieldKeys)) {
                $automaticFieldIds = DB::table('metadata_fields')
                    ->where('population_mode', 'automatic')
                    ->pluck('id')
                    ->toArray();
                $hueGroupField = DB::table('metadata_fields')->where('key', 'dominant_hue_group')->first();
                if ($hueGroupField && ! in_array($hueGroupField->id, $automaticFieldIds, true)) {
                    $automaticFieldIds[] = (int) $hueGroupField->id;
                }

                $assetMetadataValues = DB::table('asset_metadata')
                    ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
                    ->whereIn('asset_metadata.asset_id', $assetIds)
                    ->whereIn('metadata_fields.key', array_keys($filterableFieldKeys))
                    ->whereNotNull('asset_metadata.value_json')
                    ->where(function ($query) use ($automaticFieldIds) {
                        if (! empty($automaticFieldIds)) {
                            $query->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                ->orWhere(function ($q) use ($automaticFieldIds) {
                                    $q->whereNotIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                        ->whereNotNull('asset_metadata.approved_at');
                                });
                        } else {
                            $query->whereNotNull('asset_metadata.approved_at');
                        }
                    })
                    ->select('metadata_fields.key', 'metadata_fields.population_mode', 'asset_metadata.value_json', 'asset_metadata.confidence')
                    ->distinct()
                    ->get();

                foreach ($assetMetadataValues as $row) {
                    $fieldKey = $row->key;
                    $confidence = $row->confidence !== null ? (float) $row->confidence : null;
                    $populationMode = $row->population_mode ?? 'manual';
                    $isAiField = $populationMode === 'ai';
                    if ($isAiField && $this->confidenceService->shouldSuppress($fieldKey, $confidence)) {
                        continue;
                    }

                    $value = json_decode($row->value_json, true);
                    if ($value === null) {
                        continue;
                    }

                    if (! isset($availableValues[$fieldKey])) {
                        $availableValues[$fieldKey] = [];
                    }

                    $isMultiselectField = in_array($fieldKey, ['dominant_colors', 'tags'], true);
                    if ($isMultiselectField) {
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                $normalizedItem = MetadataValueNormalizer::normalizeScalar($item);
                                if ($normalizedItem !== null && ! in_array($normalizedItem, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $normalizedItem;
                                }
                            }
                        } else {
                            $normalized = MetadataValueNormalizer::normalizeScalar($value);
                            if ($normalized !== null && ! in_array($normalized, $availableValues[$fieldKey], true)) {
                                $availableValues[$fieldKey][] = $normalized;
                            }
                        }
                    } else {
                        $normalized = MetadataValueNormalizer::normalizeScalar($value);
                        if ($normalized !== null) {
                            if (! in_array($normalized, $availableValues[$fieldKey], true)) {
                                $availableValues[$fieldKey][] = $normalized;
                            }
                        }
                    }
                }

                if (isset($filterableFieldKeys['dominant_hue_group'])) {
                    $hueGroupValues = DB::table('assets')
                        ->whereIn('id', $assetIds)
                        ->whereNotNull('dominant_hue_group')
                        ->where('dominant_hue_group', '!=', '')
                        ->distinct()
                        ->pluck('dominant_hue_group')
                        ->all();
                    if (! empty($hueGroupValues)) {
                        $availableValues['dominant_hue_group'] = array_values(array_unique(array_merge(
                            $availableValues['dominant_hue_group'] ?? [],
                            $hueGroupValues
                        )));
                    }
                }

                $assetsWithMetadata = $assets->filter(function ($item) {
                    $meta = $item['metadata'] ?? null;

                    return ! empty($meta) && isset($meta['fields']);
                });

                foreach ($assetsWithMetadata as $item) {
                    $fields = ($item['metadata'] ?? [])['fields'] ?? [];
                    foreach ($fields as $fieldKey => $value) {
                        if (isset($filterableFieldKeys[$fieldKey]) && $value !== null) {
                            if (! isset($availableValues[$fieldKey])) {
                                $availableValues[$fieldKey] = [];
                            }
                            if (is_array($value)) {
                                foreach ($value as $sub) {
                                    if ($sub !== null && ! in_array($sub, $availableValues[$fieldKey], true)) {
                                        $availableValues[$fieldKey][] = $sub;
                                    }
                                }
                            } else {
                                if (! in_array($value, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $value;
                                }
                            }
                        }
                    }
                }

                $topLevelFilterKeys = ['starred', 'quality_rating'];
                foreach ($assets as $item) {
                    $meta = $item['metadata'] ?? [];
                    foreach ($topLevelFilterKeys as $key) {
                        if (! isset($filterableFieldKeys[$key])) {
                            continue;
                        }
                        if (! array_key_exists($key, $meta)) {
                            continue;
                        }
                        $v = $meta[$key];
                        if (! isset($availableValues[$key])) {
                            $availableValues[$key] = [];
                        }
                        $normalized = is_numeric($v) ? (int) $v : $v;
                        if (! in_array($normalized, $availableValues[$key], true)) {
                            $availableValues[$key][] = $normalized;
                        }
                    }
                }

                if (isset($filterableFieldKeys['tags'])) {
                    $tagValues = DB::table('asset_tags')
                        ->whereIn('asset_id', $assetIds)
                        ->distinct()
                        ->pluck('tag')
                        ->filter()
                        ->values()
                        ->all();
                    if (! empty($tagValues)) {
                        $availableValues['tags'] = array_values(array_unique(array_merge(
                            $availableValues['tags'] ?? [],
                            $tagValues
                        )));
                        sort($availableValues['tags']);
                    }
                }

                foreach ($filterableSchema as $field) {
                    $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
                    $isPrimary = ($field['is_primary'] ?? false) === true;
                    if (! $fieldKey || ! $isPrimary || ! isset($filterableFieldKeys[$fieldKey])) {
                        continue;
                    }
                    $existing = $availableValues[$fieldKey] ?? [];
                    if (! empty($existing)) {
                        continue;
                    }
                    $optionValues = [];
                    $options = $field['options'] ?? [];
                    if (! empty($options)) {
                        foreach ($options as $opt) {
                            $v = is_array($opt) ? ($opt['value'] ?? $opt['id'] ?? null) : $opt;
                            if ($v !== null && $v !== '') {
                                $optionValues[] = $v;
                            }
                        }
                    }
                    if (empty($optionValues) && ($field['type'] ?? '') === 'rating') {
                        $optionValues = [1, 2, 3, 4, 5];
                    }
                    if (! empty($optionValues)) {
                        $availableValues[$fieldKey] = array_values(array_unique(array_merge($existing, $optionValues)));
                        sort($availableValues[$fieldKey]);
                    }
                }

                if (isset($filterableFieldKeys['dominant_hue_group']) && ! empty($hueClusterCounts)) {
                    $availableValues['dominant_hue_group'] = array_values(array_unique(array_merge(
                        $availableValues['dominant_hue_group'] ?? [],
                        array_keys($hueClusterCounts)
                    )));
                }

                $availableValues = array_filter($availableValues, fn ($values) => ! empty($values));

                foreach ($availableValues as $fieldKey => $values) {
                    sort($availableValues[$fieldKey]);
                }
            }
        }

        $hueClusterService = app(HueClusterService::class);
        foreach ($filterableSchema as &$field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_hue_group') {
                $hueValues = $availableValues['dominant_hue_group'] ?? [];
                $field['options'] = array_values(array_map(function ($clusterKey) use ($hueClusterService, $hueClusterCounts) {
                    $meta = $hueClusterService->getClusterMeta((string) $clusterKey);
                    $label = $meta['label'] ?? (string) $clusterKey;
                    $threshold = $meta['threshold_deltaE'] ?? 18;
                    $count = $hueClusterCounts[(string) $clusterKey] ?? 0;

                    return [
                        'value' => (string) $clusterKey,
                        'label' => $label,
                        'swatch' => $meta['display_hex'] ?? '#999999',
                        'row_group' => $meta['row_group'] ?? 4,
                        'tooltip' => $label."\nTypical ΔE threshold: ".$threshold,
                        'count' => $count,
                    ];
                }, $hueValues));
            }
            if (($field['type'] ?? '') === 'rating') {
                $ratingValues = $availableValues[$fieldKey] ?? [1, 2, 3, 4, 5];
                $field['options'] = array_values(array_map(fn ($v) => [
                    'value' => (string) $v,
                    'label' => (string) $v,
                    'display_label' => (string) $v,
                ], $ratingValues));
            }
        }
        unset($field);

        return [
            'filterable_schema' => $filterableSchema,
            'available_values' => $availableValues,
        ];
    }
}
