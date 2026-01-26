<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\ActivityRecorder;
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
        protected UploadMetadataSchemaResolver $uploadMetadataSchemaResolver,
        protected MetadataApprovalResolver $approvalResolver
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
     * @param bool $autoApprove If true, auto-approve metadata (e.g., during upload). Default false.
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    public function persistMetadata(
        Asset $asset,
        Category $category,
        array $metadataValues,
        int $userId,
        string $assetType = 'image',
        bool $autoApprove = false
    ): void {
        // UX-2: Log context for debugging (dev-only)
        $context = $autoApprove ? 'upload' : 'edit';
        if (config('app.env') !== 'production') {
            Log::debug('[MetadataPersistence] Context: ' . $context, [
                'asset_id' => $asset->id,
                'user_id' => $userId,
                'auto_approve' => $autoApprove,
                'field_count' => count($metadataValues),
            ]);
        }

        // UX-2: Assertion - Upload context must never reject metadata due to permissions
        // This is a safety guard to ensure upload-time metadata is always accepted
        if ($autoApprove && empty($metadataValues)) {
            // This is fine - empty metadata during upload is valid
            return;
        }

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
        DB::transaction(function () use ($asset, $metadataValues, $fieldKeyToIdMap, $userId, $schema, $autoApprove) {
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
                    // Load tenant, brand, and user once for approval resolution
                    $tenant = \App\Models\Tenant::find($asset->tenant_id);
                    $brand = \App\Models\Brand::find($asset->brand_id);
                    $user = $userId ? \App\Models\User::find($userId) : null;

                    // UX-2: Assertion - Upload context must bypass approval checks
                    // During upload, metadata is always accepted regardless of user permissions
                    // Approval enforcement happens AFTER upload via MetadataApprovalResolver
                    if ($autoApprove) {
                        // Upload context: Metadata is always accepted, approval determined after asset creation
                        $requiresApproval = false;
                        
                        // UX-2: Log approval resolution path for debugging (dev-only)
                        if (config('app.env') !== 'production') {
                            $wouldRequireApproval = $tenant && $brand && $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);
                            
                            Log::debug('[MetadataPersistence] Upload context - approval deferred', [
                                'asset_id' => $asset->id,
                                'field_key' => $fieldKey,
                                'would_require_approval_post_upload' => $wouldRequireApproval,
                                'approval_resolved_after_upload' => true,
                            ]);
                        }
                    } else {
                        // Edit context: Check if approval is required
                        // Post-upload edits require approval if workflow is enabled (unless user has bypass_approval permission)
                        // Phase M-2: Pass brand for company + brand level gating
                        $requiresApproval = $tenant && $brand && $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);
                        
                        // UX-2: Log approval resolution for edit context (dev-only)
                        if (config('app.env') !== 'production') {
                            Log::debug('[MetadataPersistence] Edit context - approval check', [
                                'asset_id' => $asset->id,
                                'field_key' => $fieldKey,
                                'requires_approval' => $requiresApproval,
                                'user_has_bypass' => $user && $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval'),
                            ]);
                        }
                    }

                    // Insert asset_metadata row
                    // Phase B7: User-uploaded metadata has confidence = 1.0 and producer = 'user'
                    $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                        'asset_id' => $asset->id,
                        'metadata_field_id' => $fieldId,
                        'value_json' => json_encode($normalizedValue),
                        'source' => 'user',
                        'confidence' => 1.0, // Phase B7: User-provided values are certain
                        'producer' => 'user', // Phase B7: User-provided values are from user
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

                    // Step 4: Add timeline event for metadata submission (if approval required)
                    if ($requiresApproval && $tenant && $brand && $userId) {
                        try {
                            $user = \App\Models\User::find($userId);
                            if ($user) {
                                // Count pending fields for this asset to include in timeline
                                $pendingCount = DB::table('asset_metadata')
                                    ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
                                    ->where('asset_metadata.asset_id', $asset->id)
                                    ->whereNull('asset_metadata.approved_at')
                                    ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
                                    ->whereIn('asset_metadata.source', ['ai', 'user'])
                                    ->where('metadata_fields.population_mode', '!=', 'automatic')
                                    ->distinct('asset_metadata.metadata_field_id')
                                    ->count('asset_metadata.metadata_field_id');

                                ActivityRecorder::record(
                                    tenant: $tenant,
                                    eventType: EventType::ASSET_METADATA_UPDATED,
                                    subject: $asset,
                                    actor: $user,
                                    brand: $brand,
                                    metadata: [
                                        'action' => 'submitted_for_approval',
                                        'field_key' => $fieldKey,
                                        'field_id' => $fieldId,
                                        'field_count' => $pendingCount,
                                        'submitted_by' => $userId,
                                    ]
                                );
                            }
                        } catch (\Exception $e) {
                            // Activity logging must never break processing
                            Log::error('Failed to log metadata submission activity', [
                                'asset_id' => $asset->id,
                                'field_id' => $fieldId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        });
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
