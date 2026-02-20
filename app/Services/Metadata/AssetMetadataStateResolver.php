<?php

namespace App\Services\Metadata;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Canonical Metadata State Resolver
 * 
 * Provides a single source of truth for metadata state resolution per asset.
 * Resolves approved and pending metadata rows according to business rules.
 * 
 * Phase 3B: Version-bound metadata. When asset has currentVersion, reads from
 * $asset->currentVersion->metadata (asset_version_id). Legacy assets use asset_id.
 * 
 * This resolver is read-only and does not apply permissions or visibility rules.
 * It purely resolves the canonical state from asset_metadata rows.
 */
class AssetMetadataStateResolver
{
    /**
     * Resolve canonical metadata state for an asset.
     * 
     * Returns the effective approved row and pending proposal (if any) for each field.
     * Version-bound: uses currentVersion when available.
     * 
     * @param Asset $asset
     * @return array Shape: [metadata_field_id => ['approved' => ?AssetMetadata, 'pending' => ?AssetMetadata, 'has_pending' => bool]]
     */
    public function resolve(Asset $asset): array
    {
        $version = $asset->currentVersion;

        // Phase 3B: Version-bound metadata. When currentVersion exists, filter by asset_version_id.
        // Legacy assets (no versions) fall back to asset_id.
        $query = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected']);

        if ($version) {
            $query->where('asset_metadata.asset_version_id', $version->id);
        } else {
            $query->where('asset_metadata.asset_id', $asset->id);
        }

        $allRows = $query
            ->select(
                'asset_metadata.*',
                'metadata_fields.key',
                'metadata_fields.type',
                'metadata_fields.population_mode'
            )
            ->orderBy('asset_metadata.created_at', 'desc')
            ->get();

        // Group by metadata_field_id
        $groupedByField = $allRows->groupBy('metadata_field_id');

        $resolved = [];

        foreach ($groupedByField as $fieldId => $rows) {
            // Resolve approved row (priority order):
            // 1. approved manual_override
            // 2. approved user
            // 3. approved automatic/system
            // 4. approved ai
            $approved = $this->resolveApproved($rows);
            
            // Resolve pending row:
            // - Newest row with approved_at IS NULL
            // - ONLY IF no approved row exists
            $pending = null;
            if (!$approved) {
                $pending = $this->resolvePending($rows);
            }

            $resolved[$fieldId] = [
                'approved' => $approved,
                'pending' => $pending,
                'has_pending' => $pending !== null,
            ];
        }

        return $resolved;
    }

    /**
     * Resolve the approved metadata row for a field.
     * 
     * Priority order (first match wins):
     * 1. manual_override (highest priority)
     * 2. user
     * 3. automatic/system
     * 4. ai (lowest priority)
     * 
     * @param \Illuminate\Support\Collection $rows All rows for this field
     * @return object|null The approved row object, or null if none exists
     */
    protected function resolveApproved($rows): ?object
    {
        // Filter to only approved rows
        $approvedRows = $rows->filter(function ($row) {
            return $row->approved_at !== null;
        });

        if ($approvedRows->isEmpty()) {
            return null;
        }

        // Priority order: manual_override > user > automatic/system > ai
        $priorityOrder = ['manual_override', 'user', 'automatic', 'system', 'ai'];
        
        foreach ($priorityOrder as $source) {
            $match = $approvedRows->firstWhere('source', $source);
            if ($match) {
                return $match;
            }
        }

        // Fallback: return first approved row if no priority match
        return $approvedRows->first();
    }

    /**
     * Resolve the pending metadata row for a field.
     * 
     * Returns the newest row with approved_at IS NULL.
     * Only called when no approved row exists.
     * 
     * @param \Illuminate\Support\Collection $rows All rows for this field
     * @return object|null The pending row object, or null if none exists
     */
    protected function resolvePending($rows): ?object
    {
        // Filter to only pending rows (approved_at IS NULL)
        $pendingRows = $rows->filter(function ($row) {
            return $row->approved_at === null;
        });

        if ($pendingRows->isEmpty()) {
            return null;
        }

        // Return newest pending row (already sorted by created_at DESC)
        return $pendingRows->first();
    }

    /**
     * Check if asset has no pending metadata (all metadata is approved or automatic).
     * 
     * Used to determine if AI suggestions should be triggered after approval.
     * 
     * @param Asset $asset
     * @return bool True if there's no pending metadata requiring approval
     */
    public function hasNoPendingMetadata(Asset $asset): bool
    {
        $resolved = $this->resolve($asset);
        
        // Check if any field has pending metadata that requires approval
        // (exclude automatic fields as they don't require approval)
        $automaticFieldIds = DB::table('metadata_fields')
            ->where('population_mode', 'automatic')
            ->pluck('id')
            ->toArray();
        
        foreach ($resolved as $fieldId => $state) {
            if (!$state['has_pending']) {
                continue;
            }
            
            // Skip automatic fields (they don't require approval)
            if (in_array($fieldId, $automaticFieldIds)) {
                continue;
            }
            
            // Check if pending row is from user or AI (requires approval)
            $pendingRow = $state['pending'];
            if ($pendingRow && in_array($pendingRow->source, ['ai', 'user'])) {
                return false; // Has pending metadata requiring approval
            }
        }
        
        return true; // No pending metadata requiring approval
    }
}
