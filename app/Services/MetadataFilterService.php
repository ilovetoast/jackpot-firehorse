<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Metadata Filter Service
 *
 * Phase 2 – Step 8: Applies metadata-based filters to asset queries.
 *
 * Rules:
 * - Filters must be schema-driven
 * - Only filterable fields may be used
 * - Respects active metadata resolution (approved user values win)
 * - Latest approved value per field is used
 */
class MetadataFilterService
{
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
        foreach ($schema['fields'] ?? [] as $field) {
            // Must be filterable and visible in filters (Phase B3: filter-only fields support)
            if (($field['is_filterable'] ?? false) && ($field['show_in_filters'] ?? true)) {
                $fieldMap[$field['key']] = $field;
            }
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
        // Use whereExists with correlated subquery to get latest approved value per asset
        // This ensures we only match against the most recent approved value for each field
        $query->whereExists(function ($q) use ($fieldId, $fieldType, $operator, $value) {
            $q->select(DB::raw(1))
                ->from('asset_metadata as am')
                ->whereColumn('am.asset_id', 'assets.id')
                ->where('am.metadata_field_id', $fieldId)
                ->where('am.source', 'user')
                ->whereNotNull('am.approved_at')
                ->whereRaw("am.approved_at = (
                    SELECT MAX(approved_at)
                    FROM asset_metadata
                    WHERE asset_id = am.asset_id
                    AND metadata_field_id = ?
                    AND source = 'user'
                    AND approved_at IS NOT NULL
                )", [$fieldId]);

            // Apply operator-specific filtering on the value_json
            $this->applyOperatorFilter($q, $fieldType, $operator, $value);
        });
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
                $query->where('am.value_json', json_encode($value));
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
     * @param array $schema Resolved metadata schema
     * @return array Filterable fields with operators
     */
    public function getFilterableFields(array $schema): array
    {
        $filterable = [];

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

            // Get available operators for this field type
            $operators = $this->getOperatorsForType($fieldType);

            $filterable[] = [
                'field_id' => $field['field_id'],
                'field_key' => $field['key'],
                'display_label' => $field['display_label'] ?? $field['key'],
                'type' => $fieldType,
                'operators' => $operators,
                'options' => $field['options'] ?? [],
                'group_key' => $field['group_key'],
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
