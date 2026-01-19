<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Metadata Suggestion Service
 *
 * Phase 2 – Step 5: Generates AI-based metadata suggestions for assets.
 *
 * This service:
 * - Identifies AI-eligible metadata fields
 * - Runs AI inference (stub implementation)
 * - Stores suggestions as unapproved metadata
 * - Creates audit entries
 *
 * Rules:
 * - AI suggestions are NEVER auto-applied
 * - AI suggestions NEVER overwrite user-entered metadata
 * - AI suggestions MUST respect visibility rules
 * - AI suggestions MUST respect option visibility
 * - AI suggestions MUST include confidence score
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 */
class AiMetadataSuggestionService
{
    public function __construct(
        protected MetadataSchemaResolver $metadataSchemaResolver
    ) {
    }

    /**
     * Generate AI metadata suggestions for an asset.
     *
     * @param Asset $asset The asset to generate suggestions for
     * @param Category|null $category Optional category for schema resolution
     * @return array Array of suggestions created (for logging)
     */
    public function generateSuggestions(Asset $asset, ?Category $category = null): array
    {
        // Skip if no category (required for schema resolution)
        if (!$category) {
            Log::info('[AiMetadataSuggestion] Skipping - no category', [
                'asset_id' => $asset->id,
            ]);
            return [];
        }

        // Determine asset type for schema resolution
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema for asset context
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Filter to AI-eligible fields only
        $eligibleFields = $this->filterAiEligibleFields($schema);

        if (empty($eligibleFields)) {
            Log::info('[AiMetadataSuggestion] No AI-eligible fields', [
                'asset_id' => $asset->id,
                'category_id' => $category->id,
            ]);
            return [];
        }

        // Collect AI context
        $aiContext = $this->collectAiContext($asset);

        // Generate AI suggestions (stub implementation)
        $suggestions = $this->callAiService($asset, $eligibleFields, $aiContext);

        if (empty($suggestions)) {
            Log::info('[AiMetadataSuggestion] No suggestions generated', [
                'asset_id' => $asset->id,
            ]);
            return [];
        }

        // Validate and persist suggestions
        $persisted = $this->persistSuggestions($asset, $eligibleFields, $suggestions);

        Log::info('[AiMetadataSuggestion] Suggestions generated', [
            'asset_id' => $asset->id,
            'suggestions_count' => count($persisted),
        ]);

        return $persisted;
    }

    /**
     * Determine asset type for schema resolution.
     *
     * @param Asset $asset
     * @return string 'image' | 'video' | 'document'
     */
    protected function determineAssetType(Asset $asset): string
    {
        // Use asset type enum value, defaulting to 'image'
        $type = $asset->type?->value ?? 'image';

        // Map asset type enum to schema asset type
        if (in_array($type, ['image', 'video', 'document'], true)) {
            return $type;
        }

        // Default to 'image' for unknown types
        return 'image';
    }

    /**
     * Filter schema to AI-eligible fields only.
     *
     * AI eligibility rules:
     * - is_ai_trainable = true
     * - is_visible = true
     * - Field is NOT rating type
     * - Field is user-editable
     *
     * @param array $schema Resolved metadata schema
     * @return array Array of eligible field definitions
     */
    protected function filterAiEligibleFields(array $schema): array
    {
        // Load is_ai_trainable and is_user_editable flags from database
        // (not included in resolved schema to keep resolver focused on visibility)
        $fieldIds = array_map(fn($f) => $f['field_id'], $schema['fields'] ?? []);
        
        if (empty($fieldIds)) {
            return [];
        }

        $fieldFlags = DB::table('metadata_fields')
            ->whereIn('id', $fieldIds)
            ->select('id', 'is_ai_trainable', 'is_user_editable')
            ->get()
            ->keyBy('id');

        $eligible = [];

        foreach ($schema['fields'] ?? [] as $field) {
            $fieldId = $field['field_id'] ?? null;
            if (!$fieldId) {
                continue;
            }

            $flags = $fieldFlags[$fieldId] ?? null;
            if (!$flags) {
                continue;
            }

            // Check AI eligibility rules
            if (!($flags->is_ai_trainable ?? false)) {
                continue;
            }

            if (!($field['is_visible'] ?? false)) {
                continue;
            }

            if (($field['type'] ?? '') === 'rating') {
                continue;
            }

            if (!($flags->is_user_editable ?? true)) {
                continue;
            }

            $eligible[] = $field;
        }

        return $eligible;
    }

    /**
     * Collect AI context for inference.
     *
     * @param Asset $asset
     * @return array Context data for AI
     */
    protected function collectAiContext(Asset $asset): array
    {
        return [
            'asset_id' => $asset->id,
            'title' => $asset->title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            // Thumbnail URLs would be added here in future
            // EXIF data would be added here in future
        ];
    }

    /**
     * Call AI service to generate suggestions (stub implementation).
     *
     * @param Asset $asset
     * @param array $eligibleFields
     * @param array $context
     * @return array Array of suggestions: [field_key => [value, confidence]]
     */
    protected function callAiService(Asset $asset, array $eligibleFields, array $context): array
    {
        // Stub implementation - returns empty array
        // Future phase will add actual AI inference
        return [];
    }

    /**
     * Validate and persist AI suggestions.
     *
     * @param Asset $asset
     * @param array $eligibleFields
     * @param array $suggestions Array of [field_key => [value, confidence]]
     * @return array Array of persisted suggestion IDs
     */
    protected function persistSuggestions(Asset $asset, array $eligibleFields, array $suggestions): array
    {
        // Build map of field_key to field definition
        $fieldMap = [];
        foreach ($eligibleFields as $field) {
            $fieldMap[$field['key']] = $field;
        }

        // Check for existing user-approved metadata (AI must not overwrite)
        $existingMetadata = $this->loadExistingUserMetadata($asset);

        $persisted = [];

        // Wrap in transaction for safety
        DB::transaction(function () use ($asset, $fieldMap, $suggestions, $existingMetadata, &$persisted) {
            foreach ($suggestions as $fieldKey => $suggestionData) {
                // Skip if field not in eligible fields
                if (!isset($fieldMap[$fieldKey])) {
                    Log::warning('[AiMetadataSuggestion] Field not in eligible fields', [
                        'asset_id' => $asset->id,
                        'field_key' => $fieldKey,
                    ]);
                    continue;
                }

                $field = $fieldMap[$fieldKey];

                // Skip if user already provided value for this field
                if (isset($existingMetadata[$fieldKey])) {
                    Log::info('[AiMetadataSuggestion] Skipping - user already provided value', [
                        'asset_id' => $asset->id,
                        'field_key' => $fieldKey,
                    ]);
                    continue;
                }

                $value = $suggestionData['value'] ?? null;
                $confidence = $suggestionData['confidence'] ?? 0.0;

                // Validate value against field type and options
                if (!$this->validateSuggestionValue($field, $value)) {
                    Log::warning('[AiMetadataSuggestion] Invalid suggestion value', [
                        'asset_id' => $asset->id,
                        'field_key' => $fieldKey,
                        'value' => $value,
                    ]);
                    continue;
                }

                // Normalize value for persistence
                $normalizedValues = $this->normalizeValue($field, $value);

                // Persist each value (one row per value for multi-value fields)
                foreach ($normalizedValues as $normalizedValue) {
                    // Insert asset_metadata row (unapproved)
                    // Phase B7: AI suggestions have producer = 'ai' and use AI confidence
                    $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                        'asset_id' => $asset->id,
                        'metadata_field_id' => $field['field_id'],
                        'value_json' => json_encode($normalizedValue),
                        'source' => 'ai',
                        'confidence' => $confidence, // AI-provided confidence
                        'producer' => 'ai', // Phase B7: AI suggestions are from AI
                        'approved_at' => null, // Unapproved
                        'approved_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Insert audit history entry
                    DB::table('asset_metadata_history')->insert([
                        'asset_metadata_id' => $assetMetadataId,
                        'old_value_json' => null,
                        'new_value_json' => json_encode($normalizedValue),
                        'source' => 'ai',
                        'changed_by' => null, // AI, not a user
                        'created_at' => now(),
                    ]);

                    $persisted[] = $assetMetadataId;
                }
            }
        });

        return $persisted;
    }

    /**
     * Load existing user-approved metadata for asset.
     *
     * @param Asset $asset
     * @return array Keyed by field_key
     */
    protected function loadExistingUserMetadata(Asset $asset): array
    {
        // Load all user-approved metadata for this asset
        $userMetadata = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->where('asset_metadata.source', 'user')
            ->whereNotNull('asset_metadata.approved_at')
            ->select('metadata_fields.key', 'asset_metadata.value_json')
            ->get();

        $result = [];
        foreach ($userMetadata as $row) {
            $result[$row->key] = json_decode($row->value_json, true);
        }

        return $result;
    }

    /**
     * Validate suggestion value against field definition.
     *
     * @param array $field Field definition
     * @param mixed $value Suggested value
     * @return bool
     */
    protected function validateSuggestionValue(array $field, $value): bool
    {
        $fieldType = $field['type'] ?? 'text';

        // For select/multiselect, validate against allowed options
        if (in_array($fieldType, ['select', 'multiselect'])) {
            $allowedOptions = [];
            foreach ($field['options'] ?? [] as $option) {
                if ($option['is_visible'] ?? true) {
                    $allowedOptions[] = $option['value'];
                }
            }

            if (empty($allowedOptions)) {
                // Field has no visible options - cannot suggest
                return false;
            }

            if ($fieldType === 'multiselect') {
                if (!is_array($value)) {
                    return false;
                }
                // All values must be in allowed options
                foreach ($value as $v) {
                    if (!in_array($v, $allowedOptions, true)) {
                        return false;
                    }
                }
            } else {
                // Single select
                if (!in_array($value, $allowedOptions, true)) {
                    return false;
                }
            }
        }

        // Type-specific validation
        switch ($fieldType) {
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value);
            case 'date':
                // Validate date format (ISO 8601 or similar)
                return $this->isValidDate($value);
            case 'text':
                return is_string($value) && $value !== '';
            default:
                return true; // Unknown types - allow for now
        }
    }

    /**
     * Check if value is a valid date.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isValidDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Normalize value based on field type.
     *
     * Returns array of values (one element for single-value, multiple for multi-value).
     *
     * @param array $field Field definition
     * @param mixed $value Raw value
     * @return array Array of normalized values
     */
    protected function normalizeValue(array $field, $value): array
    {
        $fieldType = $field['type'] ?? 'text';

        // Handle multi-value fields
        if ($fieldType === 'multiselect') {
            if (!is_array($value)) {
                return [];
            }

            // Dedupe values
            $uniqueValues = array_unique($value, SORT_REGULAR);

            // Return as array of individual values
            return array_map(fn($v) => $v, $uniqueValues);
        }

        // Single-value fields - return as single-element array
        return [$value];
    }
}
