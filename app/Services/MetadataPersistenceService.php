<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Persistence Service
 *
 * Phase 2 – Step 4: Persists user-entered metadata values to asset_metadata table.
 *
 * This service handles:
 * - Validation against resolved upload schema
 * - Writing values to asset_metadata
 * - Writing audit entries to asset_metadata_history
 *
 * Rules:
 * - Only fields present in upload schema may be persisted
 * - Canonical metadata_field_id must be used (never keys)
 * - All writes must be auditable
 * - No silent data mutation
 * - No inference of defaults
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 */
class MetadataPersistenceService
{
    public function __construct(
        protected UploadMetadataSchemaResolver $uploadMetadataSchemaResolver
    ) {
    }

    /**
     * Persist metadata values for an asset.
     *
     * @param Asset $asset The asset to persist metadata for
     * @param Category $category The category used for schema resolution
     * @param array $metadataValues Metadata values keyed by field key (from frontend)
     * @param int $userId User ID who created the metadata
     * @param string $assetType Asset type for schema resolution ('image', 'video', 'document')
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    public function persistMetadata(
        Asset $asset,
        Category $category,
        array $metadataValues,
        int $userId,
        string $assetType = 'image'
    ): void {
        // Skip if no metadata values provided
        if (empty($metadataValues)) {
            return;
        }

        // Resolve upload schema to get allowlist of valid fields
        $schema = $this->uploadMetadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Build allowlist of valid field keys from resolved schema
        $allowedFieldKeys = [];
        $fieldKeyToIdMap = [];
        foreach ($schema['groups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $allowedFieldKeys[] = $field['key'];
                $fieldKeyToIdMap[$field['key']] = $field['field_id'];
            }
        }

        // Validate all provided field keys are in the allowlist
        $invalidKeys = array_diff(array_keys($metadataValues), $allowedFieldKeys);
        if (!empty($invalidKeys)) {
            throw new \InvalidArgumentException(
                'Invalid metadata fields: ' . implode(', ', $invalidKeys) . '. ' .
                'Fields must be present in the resolved upload schema.'
            );
        }

        // Persist metadata in a transaction
        DB::transaction(function () use ($asset, $metadataValues, $fieldKeyToIdMap, $userId, $schema) {
            foreach ($metadataValues as $fieldKey => $value) {
                // Skip empty values
                if ($this->isEmptyValue($value)) {
                    continue;
                }

                $fieldId = $fieldKeyToIdMap[$fieldKey] ?? null;
                if (!$fieldId) {
                    // Should not happen due to validation above, but guard anyway
                    Log::warning('[MetadataPersistence] Field ID not found', [
                        'field_key' => $fieldKey,
                        'asset_id' => $asset->id,
                    ]);
                    continue;
                }

                // Get field definition for type checking
                $fieldDef = $this->findFieldInSchema($schema, $fieldKey);
                if (!$fieldDef) {
                    Log::warning('[MetadataPersistence] Field definition not found', [
                        'field_key' => $fieldKey,
                        'asset_id' => $asset->id,
                    ]);
                    continue;
                }

                // Normalize value based on field type
                $normalizedValues = $this->normalizeValue($fieldDef, $value);

                // Persist each value (one row per value for multi-value fields)
                foreach ($normalizedValues as $normalizedValue) {
                    // Check if approval is required
                    $tenant = \App\Models\Tenant::find($asset->tenant_id);
                    $requiresApproval = $tenant && $this->approvalResolver->requiresApproval('user', $tenant);

                    // Insert asset_metadata row
                    $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                        'asset_id' => $asset->id,
                        'metadata_field_id' => $fieldId,
                        'value_json' => json_encode($normalizedValue),
                        'source' => 'user',
                        'confidence' => null,
                        'approved_at' => $requiresApproval ? null : now(),
                        'approved_by' => $requiresApproval ? null : $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Insert audit history entry
                    DB::table('asset_metadata_history')->insert([
                        'asset_metadata_id' => $assetMetadataId,
                        'old_value_json' => null,
                        'new_value_json' => json_encode($normalizedValue),
                        'source' => 'user',
                        'changed_by' => $userId,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        Log::info('[MetadataPersistence] Metadata persisted successfully', [
            'asset_id' => $asset->id,
            'fields_count' => count($metadataValues),
        ]);
    }

    /**
     * Check if a value is considered empty.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isEmptyValue($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value) && empty($value)) {
            return true;
        }

        return false;
    }

    /**
     * Find field definition in schema by key.
     *
     * @param array $schema
     * @param string $fieldKey
     * @return array|null
     */
    protected function findFieldInSchema(array $schema, string $fieldKey): ?array
    {
        foreach ($schema['groups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                if ($field['key'] === $fieldKey) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Normalize value based on field type.
     *
     * Returns array of values (one element for single-value, multiple for multi-value).
     *
     * @param array $fieldDef Field definition from schema
     * @param mixed $value Raw value from frontend
     * @return array Array of normalized values
     */
    protected function normalizeValue(array $fieldDef, $value): array
    {
        $fieldType = $fieldDef['type'] ?? 'text';

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
