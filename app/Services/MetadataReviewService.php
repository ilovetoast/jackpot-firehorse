<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Review Service
 *
 * Phase B9: Identifies metadata candidates that need human review.
 *
 * Review criteria:
 * - Fields with multiple unresolved or competing candidates
 * - Candidates where confidence < 1.0
 * - Candidates where producer != 'user'
 * - No manual_override exists
 *
 * Rules:
 * - Read-only query service (no mutations)
 * - Preserves all candidates for audit
 * - Does not modify resolution logic
 */
class MetadataReviewService
{
    /**
     * Get reviewable candidates for an asset.
     *
     * @param Asset $asset
     * @return array Review items grouped by field
     */
    public function getReviewableCandidates(Asset $asset): array
    {
        // Get all unresolved, non-dismissed candidates
        $candidates = DB::table('asset_metadata_candidates')
            ->where('asset_id', $asset->id)
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->where('confidence', '<', 1.0) // Confidence < 1.0
            ->where('producer', '!=', 'user') // Not from user
            ->orderBy('metadata_field_id')
            ->orderBy('confidence', 'desc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Group by field
        $candidatesByField = $candidates->groupBy('metadata_field_id');

        // Check for manual overrides
        $manualOverrides = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->pluck('metadata_field_id')
            ->toArray();

        // Get current resolved values
        $resolvedValues = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->whereNotNull('approved_at')
            ->get()
            ->keyBy('metadata_field_id');

        // Get field definitions
        $fieldIds = $candidatesByField->keys()->toArray();
        $fields = DB::table('metadata_fields')
            ->whereIn('id', $fieldIds)
            ->get()
            ->keyBy('id');

        $reviewItems = [];

        foreach ($candidatesByField as $fieldId => $fieldCandidates) {
            // Skip if manual override exists
            if (in_array($fieldId, $manualOverrides)) {
                continue;
            }

            // Include fields with multiple candidates OR any candidate with confidence < 1.0
            // (We already filtered for confidence < 1.0 and producer != 'user' in the query)
            $hasMultipleCandidates = $fieldCandidates->count() > 1;
            
            // If only one candidate, still include it (it meets confidence < 1.0 and producer != 'user')
            // Multiple candidates always need review for conflict resolution

            $field = $fields->get($fieldId);
            if (!$field) {
                continue;
            }

            $currentResolved = $resolvedValues->get($fieldId);

            // Get field options for select/multiselect fields (for label lookup)
            $options = [];
            if (in_array($field->type, ['select', 'multiselect'])) {
                $fieldOptions = DB::table('metadata_options')
                    ->where('metadata_field_id', $fieldId)
                    ->orderBy('system_label')
                    ->get();
                
                foreach ($fieldOptions as $option) {
                    $options[] = [
                        'value' => $option->value,
                        'display_label' => $option->system_label,
                    ];
                }
            }

            $reviewItems[] = [
                'asset_id' => $asset->id,
                'metadata_field_id' => $fieldId,
                'field_key' => $field->key,
                'field_label' => $field->system_label ?? $field->key,
                'field_type' => $field->type,
                'options' => $options, // Include options for label lookup
                'current_resolved_value' => $currentResolved ? json_decode($currentResolved->value_json, true) : null,
                'current_resolved_source' => $currentResolved ? $currentResolved->source : null,
                'current_resolved_confidence' => $currentResolved ? $currentResolved->confidence : null,
                'current_resolved_producer' => $currentResolved ? $currentResolved->producer : null,
                'candidates' => $fieldCandidates->map(function ($candidate) {
                    return [
                        'id' => $candidate->id,
                        'value' => json_decode($candidate->value_json, true),
                        'source' => $candidate->source,
                        'confidence' => $candidate->confidence,
                        'producer' => $candidate->producer,
                        'created_at' => $candidate->created_at,
                    ];
                })->values()->toArray(),
            ];
        }

        return $reviewItems;
    }

    /**
     * Get reviewable candidates across all assets (for review queue).
     *
     * @param int|null $tenantId Optional tenant filter
     * @param int|null $brandId Optional brand filter
     * @param int $limit Maximum number of items to return
     * @return array Review items
     */
    public function getReviewQueue(?int $tenantId = null, ?int $brandId = null, int $limit = 50): array
    {
        // Build query for assets with reviewable candidates
        $assetQuery = DB::table('assets')
            ->join('asset_metadata_candidates', 'assets.id', '=', 'asset_metadata_candidates.asset_id')
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.confidence', '<', 1.0)
            ->where('asset_metadata_candidates.producer', '!=', 'user')
            ->select('assets.id')
            ->distinct();

        if ($tenantId) {
            $assetQuery->where('assets.tenant_id', $tenantId);
        }

        if ($brandId) {
            $assetQuery->where('assets.brand_id', $brandId);
        }

        $assetIds = $assetQuery->limit($limit)->pluck('id')->toArray();

        $reviewItems = [];
        foreach ($assetIds as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) {
                continue;
            }

            $items = $this->getReviewableCandidates($asset);
            $reviewItems = array_merge($reviewItems, $items);
        }

        return $reviewItems;
    }
}
