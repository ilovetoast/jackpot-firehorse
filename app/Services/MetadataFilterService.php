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
            if (!isset($fieldMap[$fieldKey])) {
                continue; // Skip invalid fields
            }

            $field = $fieldMap[$fieldKey];
            $fieldId = $field['field_id'];

            // Get filter operator and value
            $operator = $filterDef['operator'] ?? 'equals';
            $value = $filterDef['value'] ?? null;

            if ($value === null || $value === '') {
                continue; // Skip empty filters
            }

            // Apply filter based on field type
            \Log::info('[MetadataFilterService] DEBUG - Applying filter', [
                'fieldKey' => $fieldKey,
                'fieldId' => $fieldId,
                'fieldType' => $field['type'],
                'operator' => $operator,
                'value' => $value,
            ]);
            $this->applyFieldFilter($query, $fieldId, $field['type'], $operator, $value);
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
        
        // Apply filter using OR condition to check both asset_metadata table AND metadata JSON column
        // This ensures compatibility with both storage methods
        $query->where(function ($q) use ($fieldId, $fieldKey, $fieldType, $operator, $value) {
            // Option 1: Check asset_metadata table (preferred - normalized storage)
            $q->whereExists(function ($subQ) use ($fieldId, $fieldType, $operator, $value) {
                $subQ->select(DB::raw(1))
                    ->from('asset_metadata as am')
                    ->whereColumn('am.asset_id', 'assets.id')
                    ->where('am.metadata_field_id', $fieldId)
                    ->whereIn('am.source', ['user', 'system', 'ai', 'manual_override']) // Include all approved sources
                    ->whereNotNull('am.approved_at')
                    ->whereRaw("am.approved_at = (
                        SELECT MAX(approved_at)
                        FROM asset_metadata
                        WHERE asset_id = am.asset_id
                        AND metadata_field_id = ?
                        AND source IN ('user', 'system', 'ai', 'manual_override')
                        AND approved_at IS NOT NULL
                    )", [$fieldId]);
                
                // Apply operator-specific filtering on the value_json
                $this->applyOperatorFilter($subQ, $fieldType, $operator, $value);
            });
            
            // Option 2: Check metadata JSON column (legacy/fallback)
            // Look in metadata->fields->{fieldKey}
            $this->applyOperatorFilterToJsonColumn($q, $fieldKey, $fieldType, $operator, $value);
        });
        
        // DEBUG: Log the filter application
        \Log::info('[MetadataFilterService] DEBUG - Filter applied', [
            'fieldId' => $fieldId,
            'fieldKey' => $fieldKey,
            'fieldType' => $fieldType,
            'operator' => $operator,
            'value' => $value,
        ]);
    }
    
    /**
     * Apply operator filter to metadata JSON column (legacy/fallback).
     * 
     * Checks metadata->fields->{fieldKey} in the assets.metadata JSON column.
     */
    protected function applyOperatorFilterToJsonColumn($query, string $fieldKey, string $fieldType, string $operator, $value): void
    {
        $jsonPath = "JSON_EXTRACT(metadata, '$.fields.{$fieldKey}')";
        
        switch ($fieldType) {
            case 'select':
                // For select, match exact value
                $encodedValue = json_encode($value);
                $query->orWhereRaw("{$jsonPath} = ?", [$encodedValue]);
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
                $query->where('am.value_json', json_encode($boolValue));
                break;

            case 'select':
                // For select fields, value_json is stored as JSON-encoded string
                // e.g., "studio" is stored as "\"studio\"" (JSON string)
                // Use JSON_UNQUOTE to compare the unquoted values for reliability
                $encodedValue = json_encode($value);
                \Log::info('[MetadataFilterService] DEBUG - select filter comparison', [
                    'value' => $value,
                    'value_type' => gettype($value),
                    'encodedValue' => $encodedValue,
                    'encodedValue_length' => strlen($encodedValue),
                ]);
                // Compare using JSON_UNQUOTE to handle any encoding differences
                // This compares the actual unquoted string values
                $query->whereRaw('JSON_UNQUOTE(am.value_json) = ?', [$value]);
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

        foreach ($schema['fields'] ?? [] as $field) {
            if (!($field['is_filterable'] ?? false)) {
                continue;
            }

            if ($field['is_internal_only'] ?? false) {
                continue;
            }

            $fieldType = $field['type'] ?? 'text';

            // Skip rating fields
            if ($fieldType === 'rating') {
                continue;
            }

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

            $filterable[] = [
                'field_id' => $field['field_id'],
                'field_key' => $field['key'],
                'display_label' => $field['display_label'] ?? $field['key'],
                'type' => $fieldType,
                'operators' => $operators,
                'options' => $field['options'] ?? [],
                'group_key' => $field['group_key'],
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
        }

        return $filterable;
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
}
