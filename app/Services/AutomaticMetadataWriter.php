<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automatic Metadata Writer Service
 *
 * Phase B6/B8: Writes automatic metadata candidates for later resolution.
 *
 * Phase B8 Update: Writes candidates to asset_metadata_candidates table instead
 * of directly to asset_metadata. Resolution happens via MetadataResolutionService.
 *
 * Rules:
 * - Writes candidates with source, confidence, and producer
 * - Never overwrites manual overrides (checked before writing)
 * - Idempotent (same candidate does not duplicate)
 */
class AutomaticMetadataWriter
{
    /**
     * Write automatic metadata values for an asset.
     *
     * @param Asset $asset
     * @param array $metadataValues Keyed by metadata_field_id => value
     * @return array Results: ['written' => [...], 'skipped' => [...]]
     */
    public function writeMetadata(Asset $asset, array $metadataValues): array
    {
        $results = [
            'written' => [],
            'skipped' => [],
        ];

        foreach ($metadataValues as $fieldId => $value) {
            try {
                $result = $this->writeField($asset, $fieldId, $value);
                if ($result['written']) {
                    $results['written'][] = $fieldId;
                } else {
                    $results['skipped'][] = [
                        'field_id' => $fieldId,
                        'reason' => $result['reason'],
                    ];
                }
            } catch (\Exception $e) {
                Log::error('[AutomaticMetadataWriter] Failed to write field', [
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
     * Write a single metadata field value.
     *
     * @param Asset $asset
     * @param int $fieldId
     * @param mixed $value
     * @return array ['written' => bool, 'reason' => string|null]
     */
    protected function writeField(Asset $asset, int $fieldId, $value): array
    {
        // Check if field exists
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->first();

        if (!$field) {
            return [
                'written' => false,
                'reason' => 'Field not found',
            ];
        }

        // Phase B8: Check if manual override exists (candidates won't override these)
        $existingOverride = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->first();

        if ($existingOverride) {
            Log::info('[AutomaticMetadataWriter] Skipping field - manual override exists', [
                'asset_id' => $asset->id,
                'field_id' => $fieldId,
                'overridden_at' => $existingOverride->overridden_at,
                'overridden_by' => $existingOverride->overridden_by,
            ]);
            return [
                'written' => false,
                'reason' => 'Manual override exists',
            ];
        }

        // Normalize value based on field type
        $normalizedValue = $this->normalizeValue($field, $value);

        // Phase B8: Compute confidence and producer for candidate
        $confidence = $this->computeConfidence($field);
        $producer = $this->determineProducer($field);

        // Check if identical candidate already exists (idempotency)
        $existingCandidate = DB::table('asset_metadata_candidates')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('value_json', json_encode($normalizedValue))
            ->where('source', $producer)
            ->where('confidence', $confidence)
            ->first();

        if ($existingCandidate) {
            Log::debug('[AutomaticMetadataWriter] Candidate already exists, skipping', [
                'asset_id' => $asset->id,
                'field_id' => $fieldId,
            ]);
            return [
                'written' => false,
                'reason' => 'Candidate already exists',
            ];
        }

        // Phase B8: Write candidate instead of directly to asset_metadata
        DB::table('asset_metadata_candidates')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'value_json' => json_encode($normalizedValue),
            'source' => $producer, // exif, ai, system
            'confidence' => $confidence,
            'producer' => $producer,
            'resolved_at' => null, // Not yet resolved
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[AutomaticMetadataWriter] Wrote metadata candidate', [
            'asset_id' => $asset->id,
            'field_id' => $fieldId,
            'field_key' => $field->key,
            'value' => $normalizedValue,
            'source' => $producer,
            'confidence' => $confidence,
        ]);

        return [
            'written' => true,
            'reason' => null,
        ];
    }

    /**
     * Normalize value based on field type.
     *
     * @param object $field
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeValue(object $field, $value): mixed
    {
        $type = $field->type ?? 'text';

        switch ($type) {
            case 'multiselect':
                // Ensure array
                if (!is_array($value)) {
                    return [$value];
                }
                return array_values(array_unique($value));

            case 'number':
                // Ensure numeric
                return is_numeric($value) ? (float) $value : $value;

            case 'boolean':
                // Ensure boolean
                return (bool) $value;

            case 'date':
                // Ensure ISO 8601 string
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('c');
                }
                return (string) $value;

            default:
                // text, textarea, select
                return (string) $value;
        }
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

    /**
     * Compute confidence for automatic metadata value.
     *
     * Phase B7: Stub implementation - returns deterministic confidence (0.7-0.9).
     * Future: Replace with actual confidence from EXIF/AI analysis.
     *
     * @param object $field
     * @return float|null Confidence value (0.0-1.0) or null
     */
    protected function computeConfidence(object $field): ?float
    {
        // Stub: Deterministic confidence based on field key hash
        // Returns values between 0.7 and 0.9 for automatic values
        $hash = crc32($field->key ?? '');
        $confidence = 0.7 + (($hash % 20) / 100); // 0.70 to 0.89
        
        return round($confidence, 2);
    }

    /**
     * Determine producer for automatic metadata value.
     *
     * Phase B7: Returns 'system' for stub values, 'exif' for future EXIF extraction.
     *
     * @param object $field
     * @return string|null Producer identifier
     */
    protected function determineProducer(object $field): ?string
    {
        $fieldKey = $field->key ?? '';
        
        // Stub: For now, all automatic values are from 'system'
        // Future: Return 'exif' for EXIF-extracted fields, 'ai' for AI-generated, etc.
        if (in_array($fieldKey, ['orientation', 'dimensions', 'color_mode', 'color_space'])) {
            return 'system'; // Stub: Will be 'exif' when real EXIF extraction is added
        }
        
        return 'system'; // Default for all automatic values
    }
}
