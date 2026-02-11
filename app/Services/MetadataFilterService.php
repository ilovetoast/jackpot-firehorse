<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Metadata Filter Service
 *
 * Phase 2 – Step 8 + Phase G: Applies metadata-based filters to asset queries.
 *
 * ⚠️ PHASE LOCK: Phase G complete. This service is production-locked. Do not refactor.
 *
 * Rules:
 * - Filters must be schema-driven
 * - Only filterable fields may be used
 * - Respects active metadata resolution (approved user values win)
 * - Latest approved value per field is used
 * - Phase C2: Respects category suppression rules via MetadataVisibilityResolver
 */
class MetadataFilterService
{
    public function __construct(
        protected MetadataVisibilityResolver $visibilityResolver
    ) {
    }
    /**
     * Apply metadata filters to asset query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Asset query builder
     * @param array $filters Filter definitions: [field_key => [operator => value]]
     * @param array $schema Resolved metadata schema
     * @return void
     */
    public function applyFilters($query, array $filters, array $schema): void
    {
        if (empty($filters)) {
            return;
        }

        // Build field map from schema
        $fieldMap = [];
        $tenant = app('tenant');
        $tenantVisibilityService = app(TenantMetadataVisibilityService::class);
        
        foreach ($schema['fields'] ?? [] as $field) {
            // Must be filterable
            if (!($field['is_filterable'] ?? false)) {
                continue;
            }
            
            // Phase G.3: Check tenant filter visibility override (presentation-layer logic)
            // NOTE: This is NOT schema logic - it's UI presentation control
            $systemShowInFilters = $field['show_in_filters'] ?? true;
            $effectiveShowInFilters = $systemShowInFilters;
            
            if ($tenant !== null && isset($field['field_id'])) {
                $overrides = $tenantVisibilityService->getFieldVisibilityOverrides($tenant, [$field['field_id']]);
                $override = $overrides[$field['field_id']] ?? null;
                
                if ($override && isset($override->is_filter_hidden)) {
                    // Tenant override exists - effective = system default AND NOT hidden
                    $effectiveShowInFilters = $systemShowInFilters && !$override->is_filter_hidden;
                }
            }
            
            if (!$effectiveShowInFilters) {
                continue;
            }
            
            $fieldMap[$field['key']] = $field;
        }

        // Apply each filter
        foreach ($filters as $fieldKey => $filterDef) {
            $operator = $filterDef['operator'] ?? 'equals';
            $value = $filterDef['value'] ?? null;

            if ($value === null || $value === '') {
                continue; // Skip empty filters
            }

            // Starred: apply even if not in fieldMap (e.g. schema/visibility). Value normalized in applyStarredFilter.
            if ($fieldKey === 'starred') {
                $this->applyStarredFilter($query, $value);
                continue;
            }

            // C9.2: Collection filter uses asset_collections pivot, not asset_metadata. Apply even if not in fieldMap (e.g. load_more).
            if ($fieldKey === 'collection') {
                $this->applyCollectionFilter($query, $value);
                continue;
            }

            // Tags filter: stored in asset_tags table, not asset_metadata (see TAGS_AS_METADATA_FIELD.md). Apply even if not in fieldMap.
            if ($fieldKey === 'tags') {
                $this->applyTagsFilter($query, $value);
                continue;
            }

            if (!isset($fieldMap[$fieldKey])) {
                continue; // Skip invalid fields
            }

            $field = $fieldMap[$fieldKey];
            $fieldId = $field['field_id'];
            $fieldType = $field['type'] ?? 'text';

            // Normalize select: frontend may send single value as string or as single-element array
            if ($fieldType === 'select' && is_array($value) && count($value) === 1) {
                $value = reset($value);
            }

            // Apply filter based on field type
            $this->applyFieldFilter($query, $fieldId, $fieldType, $operator, $value);
        }
    }

    /**
     * Apply filter for collection field (C9.2).
     * Collection membership is stored in asset_collections pivot, not asset_metadata.
     * Value can be single id or array of ids (e.g. from single dropdown: [id]).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $value Single collection id or array of collection ids
     */
    protected function applyCollectionFilter($query, $value): void
    {
        $ids = is_array($value) ? array_values($value) : [$value];
        $ids = array_filter(array_map(function ($v) {
            return is_numeric($v) ? (int) $v : null;
        }, $ids));

        if (empty($ids)) {
            return;
        }

        $query->whereExists(function ($q) use ($ids) {
            $q->select(DB::raw(1))
                ->from('asset_collections')
                ->whereColumn('asset_collections.asset_id', 'assets.id')
                ->whereIn('asset_collections.collection_id', $ids);
        });
    }

    /**
     * Apply filter for tags field. Tags are stored in asset_tags table, not asset_metadata.
     * Value can be a single tag string or array of tag strings (e.g. ['social']).
     * Matches assets that have at least one of the given tags.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $value Single tag or array of tags
     */
    protected function applyTagsFilter($query, $value): void
    {
        $tags = is_array($value) ? array_values($value) : [$value];
        $tags = array_filter(array_map(function ($v) {
            return is_string($v) ? trim($v) : null;
        }, $tags));

        if (empty($tags)) {
            return;
        }

        $query->whereExists(function ($q) use ($tags) {
            $q->select(DB::raw(1))
                ->from('asset_tags')
                ->whereColumn('asset_tags.asset_id', 'assets.id')
                ->whereIn('asset_tags.tag', $tags);
        });
    }

    /**
     * Apply starred filter using assets.metadata only (same storage as sort).
     * Starred is stored as boolean in metadata->starred (see docs/STARRED_CANONICAL.md); we still
     * accept 'true'/'1' and 'false'/'0' for legacy JSON.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $value true, 'true', 1 for "starred only"; false, 'false', 0 for "not starred"
     */
    protected function applyStarredFilter($query, $value): void
    {
        $wantStarred = $value === true || $value === 'true' || $value === 1 || $value === '1';
        if ($wantStarred) {
            $query->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(metadata, '$.starred') = true")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.starred')) IN ('true', '1')");
            });
        } else {
            $query->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(metadata, '$.starred') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.starred') = false")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.starred')) IN ('false', '0', '')");
            });
        }
    }

    /**
     * Apply filter for a specific field.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $fieldId
     * @param string $fieldType
     * @param string $operator
     * @param mixed $value
     * @return void
     */
    protected function applyFieldFilter($query, int $fieldId, string $fieldType, string $operator, $value): void
    {
        // Get field key for JSON column lookup
        $field = DB::table('metadata_fields')->where('id', $fieldId)->first();
        $fieldKey = $field->key ?? null;

        if (!$fieldKey) {
            \Log::warning('[MetadataFilterService] Field key not found for field_id', ['fieldId' => $fieldId]);
            return;
        }

        // dominant_color_bucket: exact-match semantics only, JSON_UNQUOTE comparison, OR across selected buckets
        if ($fieldKey === 'dominant_color_bucket') {
            $this->applyDominantColorBucketFilter($query, $fieldId, $value);
            return;
        }

        // Apply filter using OR condition to check both asset_metadata table AND metadata JSON column
        // This ensures compatibility with both storage methods.
        // Include all sources that can have approved values (same as AssetMetadataStateResolver):
        // user, system, ai, automatic, manual_override — so approved AI e.g. scene_classification is filterable.
        $approvedSources = ['user', 'system', 'ai', 'automatic', 'manual_override'];
        $query->where(function ($q) use ($fieldId, $fieldKey, $fieldType, $operator, $value, $approvedSources) {
            // Option 1: Check asset_metadata table (preferred - normalized storage)
            $q->whereExists(function ($subQ) use ($fieldId, $fieldType, $operator, $value, $approvedSources) {
                $subQ->select(DB::raw(1))
                    ->from('asset_metadata as am')
                    ->whereColumn('am.asset_id', 'assets.id')
                    ->where('am.metadata_field_id', $fieldId)
                    ->whereIn('am.source', $approvedSources)
                    ->whereNotNull('am.approved_at')
                    ->whereRaw("am.approved_at = (
                        SELECT MAX(approved_at)
                        FROM asset_metadata
                        WHERE asset_id = am.asset_id
                        AND metadata_field_id = ?
                        AND source IN ('" . implode("','", $approvedSources) . "')
                        AND approved_at IS NOT NULL
                    )", [$fieldId]);

                // Apply operator-specific filtering on the value_json
                $this->applyOperatorFilter($subQ, $fieldType, $operator, $value);
            });

            // Option 2: Check metadata JSON column (legacy/fallback)
            // Look in metadata->fields->{fieldKey}
            $this->applyOperatorFilterToJsonColumn($q, $fieldKey, $fieldType, $operator, $value);
        });
    }

    /**
     * Apply filter for dominant_color_bucket only.
     * Rule: operator = equals, comparison = exact match, logic = OR across selected buckets.
     * Uses JSON_UNQUOTE(am.value_json) to avoid JSON encoding mismatches; never raw value_json or LIKE.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $fieldId
     * @param mixed $value Single bucket string or array of bucket strings (e.g. ['L50_A10_B20'])
     */
    protected function applyDominantColorBucketFilter($query, int $fieldId, $value): void
    {
        $buckets = is_array($value) ? array_values($value) : [$value];
        $buckets = array_filter(array_map('strval', $buckets));

        if (empty($buckets)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($buckets), '?'));
        $approvedSources = ['user', 'system', 'ai', 'automatic', 'manual_override'];
        $query->where(function ($q) use ($fieldId, $buckets, $placeholders, $approvedSources) {
            // Option 1: asset_metadata table (canonical) — JSON_UNQUOTE exact match, IN (OR across buckets)
            $q->whereExists(function ($subQ) use ($fieldId, $buckets, $placeholders, $approvedSources) {
                $subQ->select(DB::raw(1))
                    ->from('asset_metadata as am')
                    ->whereColumn('am.asset_id', 'assets.id')
                    ->where('am.metadata_field_id', $fieldId)
                    ->whereIn('am.source', $approvedSources)
                    ->whereNotNull('am.approved_at')
                    ->whereRaw("am.approved_at = (
                        SELECT MAX(approved_at)
                        FROM asset_metadata
                        WHERE asset_id = am.asset_id
                        AND metadata_field_id = ?
                        AND source IN ('" . implode("','", $approvedSources) . "')
                        AND approved_at IS NOT NULL
                    )", [$fieldId])
                    ->whereRaw('JSON_UNQUOTE(am.value_json) IN (' . $placeholders . ')', $buckets);
            });

            // Option 2: metadata JSON column (legacy) — JSON_UNQUOTE exact match
            $jsonPath = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.fields.dominant_color_bucket'))";
            $q->orWhereRaw($jsonPath . ' IN (' . $placeholders . ')', $buckets);
        });
    }
    
    /**
     * Apply operator filter to metadata JSON column (legacy/fallback).
     * 
     * Checks metadata->fields->{fieldKey} in the assets.metadata JSON column.
     * Exception: starred and quality_rating are stored at top level (metadata->starred, metadata->quality_rating)
     * for sort/display; filter must check that path so filtering works when only assets.metadata is populated.
     */
    protected function applyOperatorFilterToJsonColumn($query, string $fieldKey, string $fieldType, string $operator, $value): void
    {
        // Sort fields (starred, quality_rating) are synced to metadata root, not metadata.fields
        $topLevelSortFields = ['starred', 'quality_rating'];
        $jsonPath = in_array($fieldKey, $topLevelSortFields, true)
            ? "JSON_EXTRACT(metadata, '$.{$fieldKey}')"
            : "JSON_EXTRACT(metadata, '$.fields.{$fieldKey}')";

        switch ($fieldType) {
            case 'select':
                // For select, match value case-insensitively (UI may send "Wordmark", DB may store "wordmark")
                $selectValue = is_array($value) ? (reset($value) ?? $value) : $value;
                $query->orWhereRaw('LOWER(JSON_UNQUOTE(' . $jsonPath . ')) = LOWER(?)', [(string) $selectValue]);
                break;
                
            case 'text':
                if ($operator === 'contains') {
                    $query->orWhereRaw("LOWER({$jsonPath}) LIKE ?", ['%' . strtolower($value) . '%']);
                } elseif ($operator === 'equals') {
                    $encodedValue = json_encode($value);
                    $query->orWhereRaw("{$jsonPath} = ?", [$encodedValue]);
                }
                break;
                
            case 'multiselect':
                // For multiselect, check if value is in array
                $query->orWhereRaw("JSON_CONTAINS({$jsonPath}, ?)", [json_encode($value)]);
                break;

            case 'boolean':
                $boolValue = $value === true || $value === 'true' || $value === 1;
                $encodedValue = json_encode($boolValue);
                $query->orWhereRaw("({$jsonPath} = ? OR JSON_UNQUOTE({$jsonPath}) = ?)", [$encodedValue, $boolValue ? 'true' : 'false']);
                break;

            case 'rating':
                // quality_rating etc.: stored as number or string at metadata root
                if ($operator === 'equals') {
                    $query->orWhereRaw("({$jsonPath} = ? OR {$jsonPath} = ? OR JSON_UNQUOTE({$jsonPath}) = ?)", [
                        json_encode((int) $value),
                        json_encode((string) $value),
                        (string) $value,
                    ]);
                }
                break;

            // Add other field types as needed
        }
    }

    /**
     * Apply operator-specific filter condition.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $fieldType
     * @param string $operator
     * @param mixed $value
     * @return void
     */
    protected function applyOperatorFilter($query, string $fieldType, string $operator, $value): void
    {
        switch ($fieldType) {
            case 'text':
                if ($operator === 'contains') {
                    $query->whereRaw("LOWER(JSON_UNQUOTE(am.value_json)) LIKE ?", ['%' . strtolower($value) . '%']);
                } elseif ($operator === 'equals') {
                    $query->where('am.value_json', json_encode($value));
                }
                break;

            case 'number':
                if ($operator === 'equals') {
                    $query->where('am.value_json', json_encode($value));
                } elseif ($operator === 'greater_than') {
                    $query->whereRaw("CAST(JSON_UNQUOTE(am.value_json) AS DECIMAL(10,2)) > ?", [$value]);
                } elseif ($operator === 'less_than') {
                    $query->whereRaw("CAST(JSON_UNQUOTE(am.value_json) AS DECIMAL(10,2)) < ?", [$value]);
                } elseif ($operator === 'range' && is_array($value) && count($value) === 2) {
                    $query->whereRaw("CAST(JSON_UNQUOTE(am.value_json) AS DECIMAL(10,2)) >= ?", [$value[0]])
                        ->whereRaw("CAST(JSON_UNQUOTE(am.value_json) AS DECIMAL(10,2)) <= ?", [$value[1]]);
                }
                break;

            case 'boolean':
                $boolValue = $value === true || $value === 'true' || $value === 1;
                // Compare using JSON_UNQUOTE so we match both JSON literal true and string "true"
                $query->whereRaw('JSON_UNQUOTE(am.value_json) = ?', [$boolValue ? 'true' : 'false']);
                break;

            case 'select':
                // For select fields, value_json is stored as JSON-encoded string.
                // Compare case-insensitively so UI label (e.g. "Wordmark") matches stored value (e.g. "wordmark").
                $query->whereRaw('LOWER(JSON_UNQUOTE(am.value_json)) = LOWER(?)', [(string) $value]);
                break;

            case 'rating':
                // Rating (e.g. quality_rating 1–5): stored as number or string; match both
                if ($operator === 'equals') {
                    $query->where(function ($q) use ($value) {
                        $q->where('am.value_json', json_encode((int) $value))
                            ->orWhere('am.value_json', json_encode((string) $value));
                    });
                }
                break;

            case 'multiselect':
                if ($operator === 'contains_any' && is_array($value)) {
                    $query->where(function ($q) use ($value) {
                        foreach ($value as $val) {
                            $q->orWhereRaw("JSON_CONTAINS(am.value_json, ?)", [json_encode($val)]);
                        }
                    });
                } elseif ($operator === 'contains_all' && is_array($value)) {
                    foreach ($value as $val) {
                        $query->whereRaw("JSON_CONTAINS(am.value_json, ?)", [json_encode($val)]);
                    }
                }
                break;

            case 'date':
                if ($operator === 'equals') {
                    $query->where('am.value_json', json_encode($value));
                } elseif ($operator === 'before') {
                    $query->whereRaw("JSON_UNQUOTE(am.value_json) < ?", [$value]);
                } elseif ($operator === 'after') {
                    $query->whereRaw("JSON_UNQUOTE(am.value_json) > ?", [$value]);
                } elseif ($operator === 'range' && is_array($value) && count($value) === 2) {
                    $query->whereRaw("JSON_UNQUOTE(am.value_json) >= ?", [$value[0]])
                        ->whereRaw("JSON_UNQUOTE(am.value_json) <= ?", [$value[1]]);
                }
                break;
        }
    }

    /**
     * Get filterable fields from schema.
     *
     * Phase C2: Respects category suppression rules via MetadataVisibilityResolver.
     * Phase C4: Respects tenant-level visibility overrides.
     *
     * @param array $schema Resolved metadata schema
     * @param Category|null $category Optional category model for suppression check
     * @param \App\Models\Tenant|null $tenant Optional tenant model for tenant-level overrides
     * @return array Filterable fields with operators
     */
    public function getFilterableFields(array $schema, ?Category $category = null, ?\App\Models\Tenant $tenant = null): array
    {
        $candidateFields = [];
        $alwaysHiddenFields = config('metadata_category_defaults.always_hidden_fields', []);

        foreach ($schema['fields'] ?? [] as $field) {
            $fieldKey = $field['key'] ?? null;
            if ($fieldKey && in_array($fieldKey, $alwaysHiddenFields, true)) {
                continue; // Behind-the-scenes fields (e.g. dimensions) never appear in More filters
            }
            if (!($field['is_filterable'] ?? false)) {
                continue;
            }

            // Log field properties before exclusion check
            \App\Support\Logging\PipelineLogger::error('SCHEMA CHECK', [
                'field_key' => $field['key'] ?? null,
                'field_id' => $field['field_id'] ?? null,
                'is_filterable' => $field['is_filterable'] ?? false,
                'is_internal_only' => $field['is_internal_only'] ?? false,
                'population_mode' => $field['population_mode'] ?? null,
                'show_in_filters' => $field['show_in_filters'] ?? null,
                'category_id' => $category?->id ?? null,
            ]);

            // System automated filters (population_mode=automatic + show_in_filters=true) should always be included
            // even if is_internal_only=true. This ensures system query-only fields like dominant_color_bucket
            // appear in filters regardless of category toggles.
            $isSystemAutomatedFilter = (
                ($field['population_mode'] ?? 'manual') === 'automatic' &&
                ($field['show_in_filters'] ?? true) === true
            );

            if ($field['is_internal_only'] ?? false) {
                if (!$isSystemAutomatedFilter) {
                    \App\Support\Logging\PipelineLogger::error('SCHEMA EXCLUDED', [
                        'field_key' => $field['key'] ?? null,
                        'reason' => 'is_internal_only=true and not a system automated filter (population_mode != automatic or show_in_filters != true)',
                    ]);
                    continue;
                }
                // System automated filter - allow it through
            }

            $fieldType = $field['type'] ?? 'text';

            // Include rating fields (e.g. quality_rating) so they can appear as primary/secondary filters
            // when is_primary is set and available_values exist; options are attached in the controller.

            $candidateFields[] = $field;
        }

        // Phase C2/C4: Apply category suppression and tenant override filtering via centralized resolver
        $visibleFields = $this->visibilityResolver->filterVisibleFields($candidateFields, $category, $tenant);

        // Phase G.3: Additional filter for tenant filter visibility overrides
        // This ensures fields hidden via filter visibility toggle are excluded
        // NOTE: This is presentation-layer logic, NOT schema logic
        $filterableFields = [];
        $tenantVisibilityService = app(TenantMetadataVisibilityService::class);
        
        foreach ($visibleFields as $field) {
            // Get system default
            $systemShowInFilters = $field['show_in_filters'] ?? true;
            
            // Phase G.3: Check tenant-level filter visibility override
            $effectiveShowInFilters = $systemShowInFilters;
            if ($tenant !== null && isset($field['field_id'])) {
                $overrides = $tenantVisibilityService->getFieldVisibilityOverrides($tenant, [$field['field_id']]);
                $override = $overrides[$field['field_id']] ?? null;
                
                if ($override && isset($override->is_filter_hidden)) {
                    // Tenant override exists - effective = system default AND NOT hidden
                    $effectiveShowInFilters = $systemShowInFilters && !$override->is_filter_hidden;
                }
            }
            
            // Skip fields that are hidden from filters via tenant override
            if (!$effectiveShowInFilters) {
                continue;
            }
            
            $filterableFields[] = $field;
        }

        // Build filterable fields array
        $filterable = [];
        foreach ($filterableFields as $field) {
            $fieldType = $field['type'] ?? 'text';
            $operators = $this->getOperatorsForType($fieldType);

            // Determine scope properties for Phase H filter visibility rules
            // Check applies_to field (from metadata_fields table)
            $appliesTo = $field['applies_to'] ?? 'all';
            
            // Metadata fields are never global filters (they're category-specific)
            // Global filters persist across category switches (Search, Category, Asset Type, Brand)
            // Metadata fields are scoped to categories and are filtered by visibility resolver
            $isGlobal = false;
            
            // Map applies_to to asset_types array for Phase H compatibility
            // 'all' means applies to all asset types (null = all asset types in Phase H)
            // Otherwise, map to array of asset types (e.g., ['image'], ['video'])
            // Phase H expects: null = all asset types, array = specific asset types
            $assetTypes = ($appliesTo === 'all') ? null : [$appliesTo];
            
            // category_ids is null for all metadata fields
            // Metadata fields are category-scoped via visibility resolver, not explicit category_ids
            // Phase H will check compatibility based on current category context
            // null means "applies to all categories" (category-scoped filters work with any category)
            $categoryIds = null;

            $entry = [
                'field_id' => $field['field_id'],
                'field_key' => $field['key'],
                'display_label' => $field['display_label'] ?? $field['key'],
                'type' => $fieldType,
                'operators' => $operators,
                'options' => $field['options'] ?? [],
                'group_key' => $field['group_key'],
                // display_widget: optional UI hint (e.g. 'toggle') — same layout in upload, edit, filters
                'display_widget' => $field['display_widget'] ?? (($field['key'] ?? '') === 'starred' ? 'toggle' : null),
                // Phase H scope properties required for filter visibility rules
                'is_global' => $isGlobal,
                'category_ids' => $categoryIds,
                'asset_types' => $assetTypes,
                // Primary metadata filters: effective_is_primary (category-scoped) determines placement
                // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
                // A field may be primary in Photography but secondary in Logos.
                // The field['is_primary'] value is already effective_is_primary from MetadataSchemaResolver:
                // Resolution order: category override > global is_primary (deprecated) > false
                // If is_primary is missing → defaults to false (rendered as secondary)
                'is_primary' => $field['is_primary'] ?? false,
            ];

            // Color swatch filter: dominant_color_bucket uses filter_type 'color' for swatch UI
            if (($field['key'] ?? '') === 'dominant_color_bucket') {
                $entry['filter_type'] = 'color';
            }

            $filterable[] = $entry;
        }

        // Order: Tags and Collection first (top of More filters), then rest preserve schema order
        $filterOrderPriority = ['tags' => 0, 'collection' => 1];
        $orderValue = static function ($entry) use ($filterOrderPriority) {
            $key = $entry['field_key'] ?? $entry['field_id'] ?? '';
            return $filterOrderPriority[$key] ?? 2;
        };
        usort($filterable, static function ($a, $b) use ($orderValue) {
            $pa = $orderValue($a);
            $pb = $orderValue($b);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return 0;
        });

        return $filterable;
    }

    /**
     * Phase M: Return filter field keys that have at least one value in the scoped asset set.
     * Used to hide empty filters (zero matching values) without changing schema or search/sort.
     *
     * @param \Illuminate\Database\Eloquent\Builder $baseQuery Scoped query (tenant, brand, category, lifecycle, search); MUST NOT include request metadata filters
     * @param array $filterableSchema Result of getFilterableFields() (entries with field_key, field_id)
     * @return array List of field_key that have at least one non-null/non-empty value in scope
     */
    public function getFieldKeysWithValuesInScope($baseQuery, array $filterableSchema): array
    {
        $fieldKeys = [];
        foreach ($filterableSchema as $field) {
            $key = $field['field_key'] ?? $field['field_id'] ?? null;
            if (is_string($key)) {
                $fieldKeys[$key] = true;
            }
        }
        if (empty($fieldKeys)) {
            return [];
        }

        $scopeIds = (clone $baseQuery)->select('assets.id');
        $hasAnyInScope = (clone $baseQuery)->exists();
        if (! $hasAnyInScope) {
            return [];
        }

        $keysWithValues = [];

        // 1) Metadata fields: asset_metadata (approved or automatic) for assets in scope
        $automaticFieldIds = DB::table('metadata_fields')
            ->where('population_mode', 'automatic')
            ->pluck('id')
            ->toArray();
        $bucketField = DB::table('metadata_fields')->where('key', 'dominant_color_bucket')->first();
        if ($bucketField && ! in_array($bucketField->id, $automaticFieldIds, true)) {
            $automaticFieldIds[] = (int) $bucketField->id;
        }

        $metadataKeys = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->whereIn('asset_metadata.asset_id', $scopeIds)
            ->whereIn('metadata_fields.key', array_keys($fieldKeys))
            ->whereNotNull('asset_metadata.value_json')
            ->where(function ($q) use ($automaticFieldIds) {
                if (! empty($automaticFieldIds)) {
                    $q->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                        ->orWhere(function ($q2) use ($automaticFieldIds) {
                            $q2->whereNotIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                ->whereNotNull('asset_metadata.approved_at');
                        });
                } else {
                    $q->whereNotNull('asset_metadata.approved_at');
                }
            })
            ->distinct()
            ->pluck('metadata_fields.key')
            ->all();
        foreach ($metadataKeys as $k) {
            $keysWithValues[$k] = true;
        }

        // 2) Collection: at least one asset in scope in a collection
        if (isset($fieldKeys['collection'])) {
            if (DB::table('asset_collections')->whereIn('asset_id', $scopeIds)->exists()) {
                $keysWithValues['collection'] = true;
            }
        }

        // 3) Tags: at least one asset in scope has a tag
        if (isset($fieldKeys['tags'])) {
            if (DB::table('asset_tags')->whereIn('asset_id', $scopeIds)->whereNotNull('tag')->where('tag', '!=', '')->exists()) {
                $keysWithValues['tags'] = true;
            }
        }

        // 4) Starred / quality_rating: top-level metadata
        foreach (['starred', 'quality_rating'] as $topKey) {
            if (! isset($fieldKeys[$topKey])) {
                continue;
            }
            $path = $topKey === 'starred' ? '$.starred' : '$.quality_rating';
            $exists = (clone $baseQuery)
                ->whereNotNull(DB::raw("JSON_EXTRACT(metadata, '{$path}')"))
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '{$path}')) != ''")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '{$path}')) IS NOT NULL")
                ->exists();
            if ($exists) {
                $keysWithValues[$topKey] = true;
            }
        }

        // 5) Fields that may only have values in metadata->fields (legacy): one EXISTS per remaining key.
        // Scale note: This adds one EXISTS subquery per JSON-only field. Fine at 10–100k assets;
        // if you add many more metadata fields long-term, consider batching or a materialized view.
        $driver = DB::connection()->getDriverName();
        foreach (array_keys($fieldKeys) as $key) {
            if (isset($keysWithValues[$key])) {
                continue;
            }
            if (in_array($key, ['collection', 'tags', 'starred', 'quality_rating'], true)) {
                continue;
            }
            $jsonPath = '$.fields.' . $key;
            if ($driver === 'pgsql') {
                $exists = (clone $baseQuery)
                    ->whereRaw("(metadata->'fields')->>? IS NOT NULL AND (metadata->'fields')->>? != ''", [$key, $key])
                    ->exists();
            } else {
                $exists = (clone $baseQuery)
                    ->whereNotNull(DB::raw("JSON_EXTRACT(metadata, '{$jsonPath}')"))
                    ->whereRaw("TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '{$jsonPath}')), '')) != ''")
                    ->exists();
            }
            if ($exists) {
                $keysWithValues[$key] = true;
            }
        }

        return array_keys($keysWithValues);
    }

    /**
     * Get available operators for a field type.
     *
     * @param string $fieldType
     * @return array
     */
    protected function getOperatorsForType(string $fieldType): array
    {
        return match ($fieldType) {
            'text' => [
                ['value' => 'contains', 'label' => 'Contains'],
                ['value' => 'equals', 'label' => 'Equals'],
            ],
            'number' => [
                ['value' => 'equals', 'label' => 'Equals'],
                ['value' => 'greater_than', 'label' => 'Greater than'],
                ['value' => 'less_than', 'label' => 'Less than'],
                ['value' => 'range', 'label' => 'Range'],
            ],
            'boolean' => [
                ['value' => 'equals', 'label' => 'Is'],
            ],
            'select' => [
                ['value' => 'equals', 'label' => 'Is'],
            ],
            'multiselect' => [
                ['value' => 'contains_any', 'label' => 'Contains any'],
                ['value' => 'contains_all', 'label' => 'Contains all'],
            ],
            'date' => [
                ['value' => 'equals', 'label' => 'Is'],
                ['value' => 'before', 'label' => 'Before'],
                ['value' => 'after', 'label' => 'After'],
                ['value' => 'range', 'label' => 'Range'],
            ],
            default => [
                ['value' => 'equals', 'label' => 'Equals'],
            ],
        };
    }

    public static function warnIfDominantColorBucketDidNotConstrain(int $countBefore, int $countAfter, array $filters): void
    {
        if ($countBefore === 0 || !isset($filters['dominant_color_bucket']['value'])) {
            return;
        }
        $val = $filters['dominant_color_bucket']['value'];
        if ($val === null || $val === '') {
            return;
        }
    }
}

