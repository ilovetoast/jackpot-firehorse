<?php

namespace App\Services;

use App\Models\Asset;
use App\Services\AiUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Metadata Suggestion Service
 *
 * Generates AI metadata suggestions without auto-applying values.
 * Suggestions are ephemeral and stored in asset.metadata['_ai_suggestions'].
 *
 * CRITICAL GUARDRAILS:
 * - DO NOT auto-write suggested values into asset metadata
 * - DO NOT modify Phase H logic
 * - DO NOT change existing metadata schemas
 * - Suggestions must be additive and ephemeral
 * - Auto-apply: NEVER
 * - Suggest-only threshold: >= 0.90
 * - Below threshold: discard silently
 * - Missing confidence: discard silently
 *
 * Suggestion Rules:
 * - Only for non-system, user-owned fields
 * - Only if field is currently empty
 * - Only from predefined allowed values (no free-text hallucinations)
 * - Only if confidence >= VERY HIGH threshold (0.90+)
 * - Deterministic (same asset + same AI output = same suggestion)
 */
class AiMetadataSuggestionService
{
    /**
     * @param AiMetadataConfidenceService $confidenceService
     * @param AiUsageService $usageService
     */
    public function __construct(
        protected AiMetadataConfidenceService $confidenceService,
        protected AiUsageService $usageService
    ) {
    }
    /**
     * Generate suggestions for an asset based on AI metadata candidates.
     *
     * @param Asset $asset The asset to generate suggestions for
     * @param array $aiMetadataValues Keyed by field_key => ['value' => mixed, 'confidence' => float, 'source' => string]
     * @return array Generated suggestions: ['field_key' => ['value' => mixed, 'confidence' => float, 'source' => string]]
     */
    public function generateSuggestions(Asset $asset, array $aiMetadataValues): array
    {
        if (!config('ai_metadata.suggestions.enabled', true)) {
            return [];
        }

        // Check AI usage cap before generating suggestions
        $tenant = \App\Models\Tenant::find($asset->tenant_id);
        if ($tenant) {
            try {
                $this->usageService->checkUsage($tenant, 'suggestions', 1);
            } catch (\App\Exceptions\PlanLimitExceededException $e) {
                // Hard stop when cap exceeded - return empty suggestions
                Log::warning('[AiMetadataSuggestionService] AI usage cap exceeded', [
                    'tenant_id' => $tenant->id,
                    'feature' => 'suggestions',
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        }

        $minConfidence = config('ai_metadata.suggestions.min_confidence', 0.90);
        $suggestions = [];

        foreach ($aiMetadataValues as $fieldKey => $aiData) {
            $value = $aiData['value'] ?? null;
            $confidence = $aiData['confidence'] ?? null;
            $source = $aiData['source'] ?? 'ai';

            // Rule 1: Strict confidence gating
            // - Missing confidence: discard silently
            // - Below threshold (0.90): discard silently
            // - Only >= 0.90 confidence values are suggested
            if (!$this->meetsConfidenceThreshold($confidence, $minConfidence)) {
                // Discard silently - no logging, no error, just skip
                continue;
            }

            // Rule 2: Only for eligible fields (non-system, user-owned, empty)
            if (!$this->isFieldEligible($asset, $fieldKey)) {
                continue;
            }

            // Rule 3: Only from predefined allowed values
            if (!$this->isValueAllowed($fieldKey, $value)) {
                continue;
            }

            // Rule 4: Only if field is currently empty
            if (!$this->isFieldEmpty($asset, $fieldKey)) {
                continue;
            }

            // Rule 5: Check if this suggestion has been dismissed
            // Dismissed suggestions never reappear (unless value differs)
            if ($this->isSuggestionDismissed($asset, $fieldKey, $value)) {
                // This exact field+value combination was dismissed - skip
                continue;
            }

            // Generate suggestion
            $suggestions[$fieldKey] = [
                'value' => $value,
                'confidence' => $confidence,
                'source' => $source,
                'generated_at' => now()->toIso8601String(),
            ];
        }

        // Track usage if suggestions were generated
        if (!empty($suggestions) && $tenant) {
            $this->usageService->trackUsage($tenant, 'suggestions', count($suggestions));
        }

        return $suggestions;
    }

    /**
     * Check if confidence meets the strict suggestion threshold.
     *
     * Rules:
     * - Missing confidence (null): discard (return false)
     * - Non-numeric confidence: discard (return false)
     * - Below threshold (< 0.90): discard (return false)
     * - At or above threshold (>= 0.90): allow (return true)
     *
     * This method ensures strict confidence gating and reuses the existing
     * confidence service's logic for handling missing/malformed confidence.
     *
     * @param mixed $confidence Confidence value (float, null, or other)
     * @param float $minConfidence Minimum threshold (default: 0.90)
     * @return bool True if confidence meets threshold, false if discarded
     */
    protected function meetsConfidenceThreshold($confidence, float $minConfidence): bool
    {
        // Missing confidence: discard silently
        if ($confidence === null) {
            return false;
        }

        // Non-numeric confidence: discard silently
        if (!is_numeric($confidence)) {
            return false;
        }

        // Cast to float for comparison
        $confidenceFloat = (float) $confidence;

        // Below threshold: discard silently
        if ($confidenceFloat < $minConfidence) {
            return false;
        }

        // At or above threshold: allow
        return true;
    }

    /**
     * Store suggestions in asset.metadata['_ai_suggestions'].
     *
     * @param Asset $asset
     * @param array $suggestions Suggestions keyed by field_key
     * @return void
     */
    public function storeSuggestions(Asset $asset, array $suggestions): void
    {
        if (empty($suggestions)) {
            return;
        }

        $metadata = $asset->metadata ?? [];
        $metadata['_ai_suggestions'] = $suggestions;

        $asset->update(['metadata' => $metadata]);

        Log::debug('[AiMetadataSuggestionService] Stored suggestions', [
            'asset_id' => $asset->id,
            'suggestion_count' => count($suggestions),
            'field_keys' => array_keys($suggestions),
        ]);
    }

    /**
     * Get existing suggestions for an asset.
     *
     * @param Asset $asset
     * @return array Suggestions keyed by field_key
     */
    public function getSuggestions(Asset $asset): array
    {
        return $asset->metadata['_ai_suggestions'] ?? [];
    }

    /**
     * Clear all suggestions for an asset.
     *
     * @param Asset $asset
     * @return void
     */
    public function clearSuggestions(Asset $asset): void
    {
        $metadata = $asset->metadata ?? [];
        unset($metadata['_ai_suggestions']);

        $asset->update(['metadata' => $metadata]);
    }

    /**
     * Check if a suggestion has been dismissed.
     *
     * Dismissed suggestions are tracked per asset + field + value.
     * Once dismissed, the same value will never be suggested again.
     * New suggestions are allowed only if the value differs.
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @param mixed $value
     * @return bool True if dismissed, false if not dismissed
     */
    public function isSuggestionDismissed(Asset $asset, string $fieldKey, $value): bool
    {
        $dismissed = $asset->metadata['_ai_suggestions_dismissed'] ?? [];

        if (!isset($dismissed[$fieldKey])) {
            return false;
        }

        // Normalize value for comparison
        $normalizedValue = $this->normalizeValueForComparison($value);
        $dismissedValues = $dismissed[$fieldKey] ?? [];

        // Check if this exact value was dismissed
        foreach ($dismissedValues as $dismissedValue) {
            $normalizedDismissed = $this->normalizeValueForComparison($dismissedValue);
            if ($this->valuesMatch($normalizedValue, $normalizedDismissed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a dismissed suggestion.
     *
     * Stores the dismissal marker in asset.metadata['_ai_suggestions_dismissed']
     * to prevent the same suggestion from reappearing.
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @param mixed $value
     * @return void
     */
    public function recordDismissal(Asset $asset, string $fieldKey, $value): void
    {
        $metadata = $asset->metadata ?? [];
        $dismissed = $metadata['_ai_suggestions_dismissed'] ?? [];

        // Initialize field array if needed
        if (!isset($dismissed[$fieldKey])) {
            $dismissed[$fieldKey] = [];
        }

        // Normalize value for storage
        $normalizedValue = $this->normalizeValueForComparison($value);

        // Check if this value is already dismissed (avoid duplicates)
        $alreadyDismissed = false;
        foreach ($dismissed[$fieldKey] as $dismissedValue) {
            $normalizedDismissed = $this->normalizeValueForComparison($dismissedValue);
            if ($this->valuesMatch($normalizedValue, $normalizedDismissed)) {
                $alreadyDismissed = true;
                break;
            }
        }

        if (!$alreadyDismissed) {
            // Store original value (not normalized) for reference
            $dismissed[$fieldKey][] = $value;
            $metadata['_ai_suggestions_dismissed'] = $dismissed;

            $asset->update(['metadata' => $metadata]);

            Log::debug('[AiMetadataSuggestionService] Recorded dismissal', [
                'asset_id' => $asset->id,
                'field_key' => $fieldKey,
                'value' => $value,
            ]);
        }
    }

    /**
     * Normalize a value for comparison.
     *
     * Handles arrays, strings, numbers, etc. to ensure consistent comparison.
     *
     * @param mixed $value
     * @return mixed Normalized value
     */
    protected function normalizeValueForComparison($value): mixed
    {
        if (is_array($value)) {
            // Sort arrays for consistent comparison
            sort($value);
            return $value;
        }

        if (is_string($value)) {
            // Trim and lowercase for string comparison
            return strtolower(trim($value));
        }

        if (is_numeric($value)) {
            // Normalize numbers (float vs int)
            return is_float($value) ? (float) $value : (int) $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        // For other types, convert to string
        return (string) $value;
    }

    /**
     * Check if two normalized values match.
     *
     * @param mixed $value1
     * @param mixed $value2
     * @return bool True if values match
     */
    protected function valuesMatch($value1, $value2): bool
    {
        // Handle arrays
        if (is_array($value1) && is_array($value2)) {
            if (count($value1) !== count($value2)) {
                return false;
            }
            // Both arrays are already sorted from normalizeValueForComparison
            return $value1 === $value2;
        }

        // Handle null
        if ($value1 === null && $value2 === null) {
            return true;
        }

        // Strict comparison for normalized values
        return $value1 === $value2;
    }

    /**
     * Check if a field is eligible for suggestions.
     *
     * Rules:
     * - Must be non-system field (not system-generated)
     * - Must be user-owned (is_user_editable = true)
     * - Must not be automatic-only (population_mode !== 'automatic')
     * - Must have ai_eligible = true
     * - Must have allowed_values (metadata_options) defined
     * - Must be select or multiselect type
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @return bool
     */
    protected function isFieldEligible(Asset $asset, string $fieldKey): bool
    {
        // Get field definition - handle both system fields (scope='system', tenant_id=null) and tenant fields
        $field = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->where(function ($query) use ($asset) {
                $query->where('scope', 'system')
                    ->orWhere(function ($q) use ($asset) {
                        $q->where('tenant_id', $asset->tenant_id)
                            ->where('scope', '!=', 'system');
                    });
            })
            ->first();

        if (!$field) {
            return false;
        }

        // Must be user-editable (system fields can be editable too)
        if (!($field->is_user_editable ?? true)) {
            return false;
        }

        // Must not be automatic-only (population_mode !== 'automatic')
        // Automatic fields are system-generated and should not have suggestions
        if (($field->population_mode ?? 'manual') === 'automatic') {
            return false;
        }

        // Must have ai_eligible = true
        // This flag is set by admins to explicitly enable AI suggestions
        if (!($field->ai_eligible ?? false)) {
            return false;
        }

        // Must be select or multiselect type (to ensure allowed_values exist)
        $fieldType = $field->type ?? 'text';
        if (!in_array($fieldType, ['select', 'multiselect'], true)) {
            return false;
        }

        // Must have allowed_values (metadata_options) defined
        // If no options exist, AI suggestions are disabled to prevent free-text hallucinations
        $optionsCount = DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->count();

        if ($optionsCount === 0) {
            return false;
        }

        // Must be enabled for the asset's category (not suppressed)
        if (!$this->isFieldEnabledForCategory($asset, $field->id)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a field is enabled for the asset's category.
     * 
     * A field is enabled if it's not suppressed for the category.
     *
     * @param Asset $asset
     * @param int $fieldId
     * @return bool
     */
    protected function isFieldEnabledForCategory(Asset $asset, int $fieldId): bool
    {
        // Get category from asset metadata
        $categoryId = $asset->metadata['category_id'] ?? null;
        if (!$categoryId) {
            // No category means field is enabled (no suppression)
            return true;
        }

        // Check if field is suppressed for this category
        // System fields: Check system-level suppression
        $field = DB::table('metadata_fields')->where('id', $fieldId)->first();
        if ($field && ($field->scope ?? null) === 'system') {
            // Check system-level category suppression
            $isSuppressed = DB::table('metadata_field_category_visibility')
                ->where('metadata_field_id', $fieldId)
                ->where('category_id', $categoryId)
                ->where('is_suppressed', true)
                ->exists();
            
            return !$isSuppressed;
        }

        // Tenant fields: Check tenant-level suppression
        $isSuppressed = DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('category_id', $categoryId)
            ->where('tenant_id', $asset->tenant_id)
            ->where('is_suppressed', true)
            ->exists();

        return !$isSuppressed;
    }

    /**
     * Check if a value is allowed (from predefined options).
     *
     * AI suggestions may ONLY choose from allowed_values (metadata_options).
     * This prevents free-text hallucinations and ensures AI respects tenant-defined constraints.
     *
     * Rules:
     * - Value must be in metadata_options for the field
     * - For multiselect, all values must be in options
     * - If no options exist, suggestions are disabled
     *
     * @param string $fieldKey
     * @param mixed $value
     * @return bool
     */
    protected function isValueAllowed(string $fieldKey, $value): bool
    {
        // Get field definition
        $field = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->first();

        if (!$field) {
            return false;
        }

        $fieldType = $field->type ?? 'text';

        // Only select/multiselect fields are eligible for AI suggestions
        // This ensures we always have allowed_values to validate against
        if (!in_array($fieldType, ['select', 'multiselect'], true)) {
            return false;
        }

        // Get allowed values from metadata_options
        $options = DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->pluck('value')
            ->toArray();

        if (empty($options)) {
            // No options defined - reject to prevent hallucinations
            // AI suggestions require allowed_values to be defined
            return false;
        }

        // For multiselect, check each value
        if ($fieldType === 'multiselect' && is_array($value)) {
            foreach ($value as $item) {
                if (!in_array($item, $options, true)) {
                    return false;
                }
            }
            return true;
        }

        // For select, check single value
        return in_array($value, $options, true);
    }

    /**
     * Check if a metadata field is currently empty for an asset.
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @return bool True if field is empty, false if it has a value
     */
    protected function isFieldEmpty(Asset $asset, string $fieldKey): bool
    {
        // Get field ID
        $field = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->where('tenant_id', $asset->tenant_id)
            ->first();

        if (!$field) {
            return false; // Field doesn't exist - not eligible
        }

        // Check asset_metadata table (authoritative source)
        $existingValue = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $field->id)
            ->whereNotNull('approved_at')
            ->first();

        if ($existingValue) {
            // Field has an approved value - not empty
            return false;
        }

        // Field is empty - eligible for suggestion
        return true;
    }

    /**
     * Generate and store suggestions for an asset.
     *
     * Convenience method that combines generateSuggestions() and storeSuggestions().
     *
     * @param Asset $asset
     * @param array $aiMetadataValues Keyed by field_key => ['value' => mixed, 'confidence' => float, 'source' => string]
     * @return array Generated suggestions
     */
    public function generateAndStoreSuggestions(Asset $asset, array $aiMetadataValues): array
    {
        $suggestions = $this->generateSuggestions($asset, $aiMetadataValues);
        
        if (!empty($suggestions)) {
            $this->storeSuggestions($asset, $suggestions);
        }

        return $suggestions;
    }
}
