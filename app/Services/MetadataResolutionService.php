<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Resolution Service
 *
 * Phase B8: Resolves metadata candidates to active values in asset_metadata.
 *
 * Resolution priority:
 * 1. manual_override (user) - always wins
 * 2. Highest confidence automatic candidate
 *
 * Rules:
 * - Never overwrites manual_override values
 * - Idempotent (safe to re-run)
 * - Preserves all candidates for later review
 */
class MetadataResolutionService
{
    /**
     * Resolve all candidates for an asset.
     *
     * @param Asset $asset
     * @return array Results: ['resolved' => [...], 'skipped' => [...]]
     */
    public function resolveCandidates(Asset $asset): array
    {
        $results = [
            'resolved' => [],
            'skipped' => [],
        ];

        // Get all unresolved candidates grouped by field
        $candidatesByField = DB::table('asset_metadata_candidates')
            ->where('asset_id', $asset->id)
            ->whereNull('resolved_at')
            ->orderBy('confidence', 'desc') // Highest confidence first
            ->orderBy('created_at', 'asc') // Oldest first for tie-breaking
            ->get()
            ->groupBy('metadata_field_id');

        foreach ($candidatesByField as $fieldId => $candidates) {
            try {
                $result = $this->resolveField($asset, $fieldId, $candidates);
                if ($result['resolved']) {
                    $results['resolved'][] = $fieldId;
                } else {
                    $results['skipped'][] = [
                        'field_id' => $fieldId,
                        'reason' => $result['reason'],
                    ];
                }
            } catch (\Exception $e) {
                Log::error('[MetadataResolutionService] Failed to resolve field', [
                    'asset_id' => $asset->id,
                    'field_id' => $fieldId,
                    'error' => $e->getMessage(),
                ]);
                $results['skipped'][] = [
                    'field_id' => $fieldId,
                    'reason' => 'error: ' . $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Resolve candidates for a single field.
     *
     * @param Asset $asset
     * @param int $fieldId
     * @param \Illuminate\Support\Collection $candidates
     * @return array ['resolved' => bool, 'reason' => string|null]
     */
    protected function resolveField(Asset $asset, int $fieldId, $candidates): array
    {
        // Check if manual override exists (always wins)
        $existingOverride = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->first();

        if ($existingOverride) {
            Log::debug('[MetadataResolutionService] Skipping field - manual override exists', [
                'asset_id' => $asset->id,
                'field_id' => $fieldId,
            ]);
            // Mark all candidates as resolved (even though we didn't use them)
            DB::table('asset_metadata_candidates')
                ->where('asset_id', $asset->id)
                ->where('metadata_field_id', $fieldId)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);
            return [
                'resolved' => false,
                'reason' => 'Manual override exists',
            ];
        }

        // Check if automatic value already exists and is current
        $existingAutomatic = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('source', 'automatic')
            ->whereNotNull('approved_at')
            ->orderBy('approved_at', 'desc')
            ->first();

        // Find best candidate (highest confidence, or first if tied)
        $bestCandidate = $candidates->first();
        if (!$bestCandidate) {
            return [
                'resolved' => false,
                'reason' => 'No candidates',
            ];
        }

        // Check if we need to update (new candidate is better or different)
        $candidateValue = json_decode($bestCandidate->value_json, true);
        $shouldUpdate = true;

        if ($existingAutomatic) {
            $existingValue = json_decode($existingAutomatic->value_json, true);
            $existingConfidence = $existingAutomatic->confidence ?? 0.0;
            $candidateConfidence = $bestCandidate->confidence ?? 0.0;

            // Only update if candidate has higher confidence or different value
            if ($this->valuesEqual($candidateValue, $existingValue) && $candidateConfidence <= $existingConfidence) {
                $shouldUpdate = false;
            }
        }

        if (!$shouldUpdate) {
            // Mark candidate as resolved even though we didn't update (idempotency)
            DB::table('asset_metadata_candidates')
                ->where('id', $bestCandidate->id)
                ->update(['resolved_at' => now()]);

            return [
                'resolved' => false,
                'reason' => 'Existing value is current',
            ];
        }

        // Write resolved value to asset_metadata
        DB::transaction(function () use ($asset, $fieldId, $bestCandidate, $candidateValue) {
            // Create new asset_metadata row (never update existing)
            DB::table('asset_metadata')->insert([
                'asset_id' => $asset->id,
                'metadata_field_id' => $fieldId,
                'value_json' => json_encode($candidateValue),
                'source' => 'automatic',
                'confidence' => $bestCandidate->confidence,
                'producer' => $bestCandidate->producer,
                'approved_at' => now(),
                'approved_by' => null, // System-generated
                'overridden_at' => null,
                'overridden_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Mark candidate as resolved
            DB::table('asset_metadata_candidates')
                ->where('id', $bestCandidate->id)
                ->update(['resolved_at' => now()]);
        });

        Log::info('[MetadataResolutionService] Resolved candidate to asset_metadata', [
            'asset_id' => $asset->id,
            'field_id' => $fieldId,
            'candidate_id' => $bestCandidate->id,
            'source' => $bestCandidate->source,
            'confidence' => $bestCandidate->confidence,
        ]);

        return [
            'resolved' => true,
            'reason' => null,
        ];
    }

    /**
     * Compare two values for equality (handles arrays and primitives).
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected function valuesEqual($a, $b): bool
    {
        if (is_array($a) && is_array($b)) {
            sort($a);
            sort($b);
            return $a === $b;
        }

        return $a === $b;
    }
}
