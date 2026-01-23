<?php

namespace App\Services;

/**
 * AI Metadata Confidence Service
 *
 * Handles confidence threshold checking for AI-generated metadata.
 * This is a PRESENTATION + QUERY-LAYER service only.
 *
 * CRITICAL GUARDRAILS:
 * - NO schema changes
 * - NO metadata rewrites
 * - NO Phase H modifications
 * - Additive, reversible logic only
 */
class AiMetadataConfidenceService
{
    /**
     * Check if a metadata value should be suppressed based on confidence threshold.
     *
     * @param string $fieldKey Metadata field key (e.g., 'ai_color_palette')
     * @param float|null $confidence Confidence value (0.0 to 1.0, or null if missing)
     * @return bool True if value should be suppressed (below threshold), false if visible
     */
    public function shouldSuppress(string $fieldKey, ?float $confidence): bool
    {
        // Only apply to AI metadata fields
        if (!in_array($fieldKey, config('ai_metadata.ai_metadata_fields', []), true)) {
            // Not an AI metadata field - never suppress
            return false;
        }

        // If confidence is missing or malformed, treat as BELOW threshold (suppress)
        if ($confidence === null || !is_numeric($confidence)) {
            return true;
        }

        // Get threshold for this field
        $threshold = config("ai_metadata.confidence_thresholds.{$fieldKey}", 1.0);

        // Suppress if confidence is below threshold
        return $confidence < $threshold;
    }

    /**
     * Check if a metadata value should be visible (not suppressed).
     *
     * @param string $fieldKey Metadata field key
     * @param float|null $confidence Confidence value
     * @return bool True if value should be visible, false if suppressed
     */
    public function shouldShow(string $fieldKey, ?float $confidence): bool
    {
        return !$this->shouldSuppress($fieldKey, $confidence);
    }

    /**
     * Get the confidence threshold for a field.
     *
     * @param string $fieldKey Metadata field key
     * @return float Threshold value (0.0 to 1.0)
     */
    public function getThreshold(string $fieldKey): float
    {
        return config("ai_metadata.confidence_thresholds.{$fieldKey}", 1.0);
    }

    /**
     * Check if a field is subject to confidence filtering.
     *
     * @param string $fieldKey Metadata field key
     * @return bool True if field is AI metadata and subject to filtering
     */
    public function isAiMetadataField(string $fieldKey): bool
    {
        return in_array($fieldKey, config('ai_metadata.ai_metadata_fields', []), true);
    }
}
