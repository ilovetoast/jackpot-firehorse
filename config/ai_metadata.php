<?php

/**
 * AI Metadata Confidence Thresholds
 *
 * Defines minimum confidence thresholds for AI-generated metadata fields.
 * Values below these thresholds are suppressed from filters and frontend displays.
 *
 * These thresholds are:
 * - Non-user-editable (config only)
 * - Centralized (single source of truth)
 * - NOT stored per asset
 * - NOT tenant-editable (yet)
 *
 * CRITICAL: This is a PRESENTATION + QUERY-LAYER change only.
 * - NO schema changes
 * - NO metadata rewrites
 * - NO Phase H modifications
 * - Additive, reversible logic only
 */

return [
    /**
     * Confidence thresholds for AI-generated metadata fields.
     * 
     * Threshold values are between 0.0 and 1.0 (0% to 100% confidence).
     * Values at or above the threshold are visible.
     * Values below the threshold are suppressed.
     * 
     * If confidence is missing or malformed, treat as BELOW threshold (suppress).
     */
    'confidence_thresholds' => [
        /**
         * AI Color Palette
         * Threshold: 0.80 (80% confidence required)
         */
        'ai_color_palette' => env('AI_METADATA_THRESHOLD_COLOR_PALETTE', 0.80),

        /**
         * AI Detected Objects
         * Threshold: 0.70 (70% confidence required)
         */
        'ai_detected_objects' => env('AI_METADATA_THRESHOLD_DETECTED_OBJECTS', 0.70),

        /**
         * Scene Classification
         * Threshold: 0.75 (75% confidence required)
         */
        'scene_classification' => env('AI_METADATA_THRESHOLD_SCENE_CLASSIFICATION', 0.75),
    ],

    /**
     * List of AI metadata field keys that are subject to confidence filtering.
     * Only fields in this list are checked against thresholds.
     * 
     * Deterministic system metadata (orientation, resolution, etc.) are NOT included.
     */
    'ai_metadata_fields' => [
        'ai_color_palette',
        'ai_detected_objects',
        'scene_classification',
    ],

    /**
     * AI Metadata Suggestions Configuration
     *
     * Defines thresholds and rules for generating AI metadata suggestions.
     * Suggestions are ephemeral and stored in asset.metadata['_ai_suggestions'].
     *
     * STRICT CONFIDENCE GATING RULES:
     * - Auto-apply: NEVER (suggestions are never auto-applied)
     * - Suggest-only threshold: >= 0.90 (90% confidence required)
     * - Below threshold: discard silently (no logging, no error)
     * - Missing confidence: discard silently (null or non-numeric)
     * - Centralized: All thresholds defined here (single source of truth)
     */
    'suggestions' => [
        /**
         * Minimum confidence threshold for generating suggestions.
         * Only AI values with confidence >= this threshold will be suggested.
         * 
         * STRICT RULES:
         * - Values below this threshold are discarded silently
         * - Missing confidence (null) is discarded silently
         * - Non-numeric confidence is discarded silently
         * - Only values >= 0.90 are suggested (very high confidence only)
         * 
         * Default: 0.90 (90% confidence required)
         * 
         * This is a STRICT threshold - suggestions require very high confidence
         * to prevent low-quality suggestions from appearing to users.
         */
        'min_confidence' => env('AI_METADATA_SUGGESTION_MIN_CONFIDENCE', 0.90),

        /**
         * Whether suggestions are enabled.
         * Set to false to disable suggestion generation entirely.
         */
        'enabled' => env('AI_METADATA_SUGGESTIONS_ENABLED', true),
    ],
];
