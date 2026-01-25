<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bulk Metadata Service
 *
 * Phase 2 – Step 7: Handles bulk metadata operations across multiple assets.
 *
 * Rules:
 * - All operations must be previewable
 * - All operations must be confirmed explicitly
 * - Never overwrites existing metadata rows
 * - Every affected asset produces new asset_metadata rows
 * - Audit trail required for every change
 */
class BulkMetadataService
{
    public function __construct(
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected MetadataPermissionResolver $permissionResolver
    ) {
    }

    /**
     * Preview bulk metadata operation.
     *
     * @param array $assetIds
     * @param string $operationType 'add' | 'replace' | 'clear'
     * @param array $metadataValues Keyed by field_key
     * @param int $tenantId
     * @param int $brandId
     * @param string|null $userRole Optional user role for permission checks
     * @return array Preview results
     */
    public function preview(
        array $assetIds,
        string $operationType,
        array $metadataValues,
        int $tenantId,
        int $brandId,
        ?string $userRole = null
    ): array {
        // Validate operation type
        if (!in_array($operationType, ['add', 'replace', 'clear'], true)) {
            throw new \InvalidArgumentException("Invalid operation type: {$operationType}");
        }

        // Load assets
        $assets = Asset::whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->get();

        if ($assets->count() !== count($assetIds)) {
            throw new \InvalidArgumentException('Some assets not found or not accessible');
        }

        $preview = [
            'total_assets' => count($assetIds),
            'affected_assets' => [],
            'warnings' => [],
            'errors' => [],
        ];

        foreach ($assets as $asset) {
            $assetPreview = $this->previewAsset(
                $asset,
                $operationType,
                $metadataValues,
                $tenantId,
                $brandId
            );

            if (!empty($assetPreview['errors'])) {
                $preview['errors'][] = [
                    'asset_id' => $asset->id,
                    'asset_title' => $asset->title ?? $asset->original_filename,
                    'errors' => $assetPreview['errors'],
                ];
            } else {
                $preview['affected_assets'][] = [
                    'asset_id' => $asset->id,
                    'asset_title' => $asset->title ?? $asset->original_filename,
                    'changes' => $assetPreview['changes'],
                ];
            }

            if (!empty($assetPreview['warnings'])) {
                $preview['warnings'][] = [
                    'asset_id' => $asset->id,
                    'asset_title' => $asset->title ?? $asset->original_filename,
                    'warnings' => $assetPreview['warnings'],
                ];
            }
        }

        return $preview;
    }

    /**
     * Preview operation for a single asset.
     *
     * @param Asset $asset
     * @param string $operationType
     * @param array $metadataValues
     * @param int $tenantId
     * @param int $brandId
     * @return array
     */
    protected function previewAsset(
        Asset $asset,
        string $operationType,
        array $metadataValues,
        int $tenantId,
        int $brandId,
        ?string $userRole = null
    ): array {
        $result = [
            'changes' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Load category
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            $result['errors'][] = 'Category not found';
            return $result;
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $tenantId,
            $brandId,
            $category->id,
            $assetType
        );

        // Build field map
        $fieldMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldMap[$field['key']] = $field;
        }

        // Load current approved metadata
        $currentMetadata = $this->loadCurrentMetadata($asset);

        // Process each field in metadataValues
        foreach ($metadataValues as $fieldKey => $newValue) {
            if (!isset($fieldMap[$fieldKey])) {
                $result['errors'][] = "Field '{$fieldKey}' not found in schema";
                continue;
            }

            $field = $fieldMap[$fieldKey];

            // Check if field is user-editable
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (!$fieldDef || !($fieldDef->is_user_editable ?? true)) {
                $result['errors'][] = "Field '{$fieldKey}' is not editable";
                continue;
            }

            // Check if field is internal-only
            if ($field['is_internal_only'] ?? false) {
                $result['errors'][] = "Field '{$fieldKey}' is internal-only";
                continue;
            }

            // Phase 4: Check edit permission
            if ($userRole !== null) {
                $canEdit = $this->permissionResolver->canEdit(
                    $field['field_id'],
                    $userRole,
                    $tenantId,
                    $brandId,
                    $category->id
                );

                if (!$canEdit) {
                    $result['warnings'][] = "You don't have permission to edit field '{$fieldKey}'";
                    continue; // Skip this field
                }
            }

            // Get current value
            $oldValue = $currentMetadata[$fieldKey] ?? null;

            // Handle operation type
            if ($operationType === 'clear') {
                $newValue = null;
            }

            // Validate value
            if ($newValue !== null && !$this->validateValue($fieldDef, $newValue)) {
                $result['errors'][] = "Invalid value for field '{$fieldKey}'";
                continue;
            }

            // Check if value would change
            if ($this->valuesEqual($oldValue, $newValue, $field['type'])) {
                $result['warnings'][] = "Field '{$fieldKey}' already has this value";
                continue;
            }

            $result['changes'][] = [
                'field_key' => $fieldKey,
                'field_label' => $field['display_label'] ?? $fieldKey,
                'field_type' => $field['type'],
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'source' => 'user',
            ];
        }

        return $result;
    }

    /**
     * Execute bulk metadata operation.
     *
     * @param array $assetIds
     * @param string $operationType
     * @param array $metadataValues
     * @param int $tenantId
     * @param int $brandId
     * @param int $userId
     * @param string|null $userRole Optional user role for permission checks
     * @return array Execution results
     */
    public function execute(
        array $assetIds,
        string $operationType,
        array $metadataValues,
        int $tenantId,
        int $brandId,
        int $userId,
        ?string $userRole = null
    ): array {
        // Validate operation type
        if (!in_array($operationType, ['add', 'replace', 'clear'], true)) {
            throw new \InvalidArgumentException("Invalid operation type: {$operationType}");
        }

        // Load assets
        $assets = Asset::whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->get();

        $results = [
            'total_assets' => count($assetIds),
            'successes' => [],
            'failures' => [],
        ];

        // Process in chunks of 100
        $chunks = $assets->chunk(100);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $asset) {
                try {
                    // Each asset in its own transaction
                    DB::transaction(function () use ($asset, $operationType, $metadataValues, $tenantId, $brandId, $userId, $userRole) {
                        $this->executeAsset(
                            $asset,
                            $operationType,
                            $metadataValues,
                            $tenantId,
                            $brandId,
                            $userId,
                            $userRole
                        );
                    });

                    $results['successes'][] = [
                        'asset_id' => $asset->id,
                        'asset_title' => $asset->title ?? $asset->original_filename,
                    ];
                } catch (\Exception $e) {
                    Log::error('[BulkMetadataService] Failed to process asset', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);

                    $results['failures'][] = [
                        'asset_id' => $asset->id,
                        'asset_title' => $asset->title ?? $asset->original_filename,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Execute operation for a single asset.
     *
     * @param Asset $asset
     * @param string $operationType
     * @param array $metadataValues
     * @param int $tenantId
     * @param int $brandId
     * @param int $userId
     * @param string|null $userRole Optional user role for permission checks
     * @return void
     */
    protected function executeAsset(
        Asset $asset,
        string $operationType,
        array $metadataValues,
        int $tenantId,
        int $brandId,
        int $userId,
        ?string $userRole = null
    ): void {
        // Load category
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            throw new \RuntimeException('Category not found');
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $tenantId,
            $brandId,
            $category->id,
            $assetType
        );

        // Build field map
        $fieldMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldMap[$field['key']] = $field;
        }

        // Load current approved metadata
        $currentMetadata = $this->loadCurrentMetadata($asset);

        // Process each field
        foreach ($metadataValues as $fieldKey => $newValue) {
            if (!isset($fieldMap[$fieldKey])) {
                continue; // Skip invalid fields
            }

            $field = $fieldMap[$fieldKey];

            // Check if field is user-editable
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (!$fieldDef || !($fieldDef->is_user_editable ?? true)) {
                continue; // Skip non-editable fields
            }

            // Check if field is internal-only
            if ($field['is_internal_only'] ?? false) {
                continue; // Skip internal-only fields
            }

            // Phase B2: Skip readonly fields (automatic or explicitly readonly)
            $populationMode = $field['population_mode'] ?? 'manual';
            $isReadonly = ($field['readonly'] ?? false) || ($populationMode === 'automatic');
            if ($isReadonly) {
                continue; // Skip readonly fields
            }

            // Phase 4: Check edit permission
            if ($userRole !== null) {
                $canEdit = $this->permissionResolver->canEdit(
                    $field['field_id'],
                    $userRole,
                    $tenantId,
                    $brandId,
                    $category->id
                );

                if (!$canEdit) {
                    continue; // Skip fields user cannot edit
                }
            }

            // Handle operation type
            if ($operationType === 'clear') {
                $newValue = null;
            }

            // Skip if value is empty and operation is not clear
            if ($newValue === null || $newValue === '') {
                if ($operationType !== 'clear') {
                    continue;
                }
            }

            // Validate value
            if ($newValue !== null && !$this->validateValue($fieldDef, $newValue)) {
                continue; // Skip invalid values
            }

            // Get previous value for audit
            $oldValue = $currentMetadata[$fieldKey] ?? null;
            $oldValueJson = $oldValue !== null ? json_encode($oldValue) : null;

            // Normalize value
            $normalizedValues = $this->normalizeValue($fieldDef, $newValue);

            // Persist each value
            foreach ($normalizedValues as $value) {
                // Check if approval is required (unless user has bypass_approval permission)
                // Phase M-2: Pass brand for company + brand level gating
                $tenant = \App\Models\Tenant::find($asset->tenant_id);
                $brand = \App\Models\Brand::find($asset->brand_id);
                $user = $userId ? \App\Models\User::find($userId) : null;
                $requiresApproval = $tenant && $brand && $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);

                // Create new asset_metadata row
                // Phase B7: Bulk user edits have confidence = 1.0 and producer = 'user'
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field['field_id'],
                    'value_json' => json_encode($value),
                    'source' => 'user',
                    'confidence' => 1.0, // Phase B7: User edits are certain
                    'producer' => 'user', // Phase B7: User edits are from user
                    'approved_at' => $requiresApproval ? null : now(),
                    'approved_by' => $requiresApproval ? null : $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit history entry
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => $oldValueJson,
                    'new_value_json' => json_encode($value),
                    'source' => 'user',
                    'changed_by' => $userId,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Load current approved metadata for an asset.
     *
     * @param Asset $asset
     * @return array Keyed by field_key
     */
    protected function loadCurrentMetadata(Asset $asset): array
    {
        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->where('asset_metadata.source', 'user')
            ->whereNotNull('asset_metadata.approved_at')
            ->select(
                'metadata_fields.key',
                'metadata_fields.type',
                'asset_metadata.value_json'
            )
            ->orderBy('asset_metadata.approved_at', 'desc')
            ->get()
            ->groupBy('key');

        $result = [];
        foreach ($rows as $key => $keyRows) {
            $fieldType = $keyRows->first()->type ?? 'text';

            if ($fieldType === 'multiselect') {
                $allValues = [];
                foreach ($keyRows as $row) {
                    $value = json_decode($row->value_json, true);
                    if (is_array($value)) {
                        $allValues = array_merge($allValues, $value);
                    } else {
                        $allValues[] = $value;
                    }
                }
                $result[$key] = array_unique($allValues, SORT_REGULAR);
            } else {
                $mostRecent = $keyRows->first();
                $result[$key] = json_decode($mostRecent->value_json, true);
            }
        }

        return $result;
    }

    /**
     * Determine asset type.
     *
     * @param Asset $asset
     * @return string
     */
    protected function determineAssetType(Asset $asset): string
    {
        $type = $asset->type?->value ?? 'image';
        return in_array($type, ['image', 'video', 'document'], true) ? $type : 'image';
    }

    /**
     * Validate value against field type.
     *
     * @param object $field
     * @param mixed $value
     * @return bool
     */
    protected function validateValue(object $field, $value): bool
    {
        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            return is_array($value) && !empty($value);
        }

        switch ($fieldType) {
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value);
            case 'date':
                return $this->isValidDate($value);
            case 'text':
                return is_string($value) && $value !== '';
            case 'select':
                return $value !== null && $value !== '';
            default:
                return true;
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
     * @param object $field
     * @param mixed $value
     * @return array
     */
    protected function normalizeValue(object $field, $value): array
    {
        if ($value === null) {
            return [null]; // Clear operation
        }

        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            if (!is_array($value)) {
                return [];
            }
            return array_map(fn($v) => $v, array_unique($value, SORT_REGULAR));
        }

        return [$value];
    }

    /**
     * Check if two values are equal.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     * @param string $fieldType
     * @return bool
     */
    protected function valuesEqual($oldValue, $newValue, string $fieldType): bool
    {
        if ($oldValue === null && $newValue === null) {
            return true;
        }

        if ($oldValue === null || $newValue === null) {
            return false;
        }

        if ($fieldType === 'multiselect') {
            $oldArray = is_array($oldValue) ? $oldValue : [$oldValue];
            $newArray = is_array($newValue) ? $newValue : [$newValue];
            sort($oldArray);
            sort($newArray);
            return $oldArray === $newArray;
        }

        return $oldValue === $newValue;
    }
}
