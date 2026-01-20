<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * System Metadata Registry Service
 *
 * Phase C1, Step 1: Read-only service for querying system metadata fields
 * with aggregate metrics and informational flags.
 *
 * This service provides observability into system-provided metadata fields
 * without allowing any mutations.
 *
 * Rules:
 * - Read-only (no writes, no mutations)
 * - System fields only (scope = 'system')
 * - No tenant-level logic
 * - No visibility enforcement changes
 */
class SystemMetadataRegistryService
{
    /**
     * Get all system metadata fields with metrics and informational flags.
     *
     * @return array Array of field data with metrics
     */
    public function getSystemFields(): array
    {
        // Query system metadata fields
        $fields = DB::table('metadata_fields')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->select([
                'id',
                'key',
                'system_label',
                'type',
                'applies_to',
                'population_mode',
                'show_on_upload',
                'show_on_edit',
                'show_in_filters',
                'readonly',
                'group_key',
                'is_filterable',
                'is_user_editable',
                'is_ai_trainable',
                'is_internal_only',
            ])
            ->orderBy('key')
            ->get();

        $fieldIds = $fields->pluck('id')->toArray();

        // Get aggregate metrics for all fields at once
        $metrics = $this->getFieldMetrics($fieldIds);

        // Get AI-related flags (check candidates table for producer = 'ai')
        $aiRelatedFields = $this->getAiRelatedFields($fieldIds);

        // Build result array
        $result = [];
        foreach ($fields as $field) {
            $fieldId = $field->id;
            $fieldMetrics = $metrics[$fieldId] ?? [
                'total_assets_with_value' => 0,
                'percent_populated' => 0.0,
                'percent_user_override' => 0.0,
                'pending_review_count' => 0,
            ];

            // Derive informational flags
            $isFilterOnly = $field->show_in_filters
                && !$field->show_on_edit
                && !$field->show_on_upload;

            $isAiRelated = in_array($fieldId, $aiRelatedFields);
            $isSystemGenerated = ($field->population_mode ?? 'manual') === 'automatic';
            $supportsOverride = ($field->population_mode ?? 'manual') === 'hybrid';

            $result[] = [
                'id' => $field->id,
                'key' => $field->key,
                'label' => $field->system_label,
                'field_type' => $field->type,
                'applies_to' => $field->applies_to,
                'population_mode' => $field->population_mode ?? 'manual',
                'show_on_upload' => (bool) ($field->show_on_upload ?? true),
                'show_on_edit' => (bool) ($field->show_on_edit ?? true),
                'show_in_filters' => (bool) ($field->show_in_filters ?? true),
                'readonly' => (bool) ($field->readonly ?? false),
                'group_key' => $field->group_key,
                'is_filterable' => (bool) $field->is_filterable,
                'is_user_editable' => (bool) $field->is_user_editable,
                'is_ai_trainable' => (bool) $field->is_ai_trainable,
                'is_internal_only' => (bool) $field->is_internal_only,
                // Derived flags
                'is_filter_only' => $isFilterOnly,
                'is_ai_related' => $isAiRelated,
                'is_system_generated' => $isSystemGenerated,
                'supports_override' => $supportsOverride,
                // Metrics
                'total_assets_with_value' => $fieldMetrics['total_assets_with_value'],
                'percent_populated' => round($fieldMetrics['percent_populated'], 2),
                'percent_user_override' => round($fieldMetrics['percent_user_override'], 2),
                'pending_review_count' => $fieldMetrics['pending_review_count'],
            ];
        }

        return $result;
    }

    /**
     * Get aggregate metrics for metadata fields.
     *
     * @param array $fieldIds
     * @return array Keyed by field_id
     */
    protected function getFieldMetrics(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        // Get total asset count (for percentage calculations)
        $totalAssets = DB::table('assets')->count();

        if ($totalAssets === 0) {
            // Return zeros if no assets exist
            $result = [];
            foreach ($fieldIds as $fieldId) {
                $result[$fieldId] = [
                    'total_assets_with_value' => 0,
                    'percent_populated' => 0.0,
                    'percent_user_override' => 0.0,
                    'pending_review_count' => 0,
                ];
            }
            return $result;
        }

        // Get assets with values for each field
        $assetsWithValue = DB::table('asset_metadata')
            ->whereIn('metadata_field_id', $fieldIds)
            ->select('metadata_field_id', DB::raw('COUNT(DISTINCT asset_id) as count'))
            ->groupBy('metadata_field_id')
            ->pluck('count', 'metadata_field_id')
            ->toArray();

        // Get user overrides (overridden_at is not null)
        $userOverrides = DB::table('asset_metadata')
            ->whereIn('metadata_field_id', $fieldIds)
            ->whereNotNull('overridden_at')
            ->select('metadata_field_id', DB::raw('COUNT(DISTINCT asset_id) as count'))
            ->groupBy('metadata_field_id')
            ->pluck('count', 'metadata_field_id')
            ->toArray();

        // Get pending review count (candidates that haven't been resolved)
        $pendingReview = DB::table('asset_metadata_candidates')
            ->whereIn('metadata_field_id', $fieldIds)
            ->whereNull('resolved_at')
            ->select('metadata_field_id', DB::raw('COUNT(*) as count'))
            ->groupBy('metadata_field_id')
            ->pluck('count', 'metadata_field_id')
            ->toArray();

        // Build result
        $result = [];
        foreach ($fieldIds as $fieldId) {
            $assetsWithValueCount = $assetsWithValue[$fieldId] ?? 0;
            $userOverrideCount = $userOverrides[$fieldId] ?? 0;
            $pendingCount = $pendingReview[$fieldId] ?? 0;

            $percentPopulated = $totalAssets > 0
                ? ($assetsWithValueCount / $totalAssets) * 100
                : 0.0;

            $percentUserOverride = $assetsWithValueCount > 0
                ? ($userOverrideCount / $assetsWithValueCount) * 100
                : 0.0;

            $result[$fieldId] = [
                'total_assets_with_value' => $assetsWithValueCount,
                'percent_populated' => $percentPopulated,
                'percent_user_override' => $percentUserOverride,
                'pending_review_count' => $pendingCount,
            ];
        }

        return $result;
    }

    /**
     * Get field IDs that have AI-related candidates.
     *
     * @param array $fieldIds
     * @return array Array of field IDs that have AI candidates
     */
    protected function getAiRelatedFields(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        // Check candidates table for producer = 'ai'
        $aiFields = DB::table('asset_metadata_candidates')
            ->whereIn('metadata_field_id', $fieldIds)
            ->where('producer', 'ai')
            ->distinct()
            ->pluck('metadata_field_id')
            ->toArray();

        return $aiFields;
    }
}
