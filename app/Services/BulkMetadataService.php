<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\TagNormalizationService;

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
        protected MetadataPermissionResolver $permissionResolver,
        protected MetadataApprovalResolver $approvalResolver,
        protected MetadataPersistenceService $metadataPersistenceService,
        protected TagNormalizationService $tagNormalizationService,
    ) {}

    /**
     * Preview bulk metadata operation.
     *
     * @param  string  $operationType  'add' | 'replace' | 'clear' | 'remove' (remove = tags only; strip listed values)
     * @param  array  $metadataValues  Keyed by field_key
     * @param  string|null  $userRole  Optional user role for permission checks
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
        if (! in_array($operationType, ['add', 'replace', 'clear', 'remove'], true)) {
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

        if ($operationType === 'remove') {
            $this->assertRemoveMetadataPayload($metadataValues, $tenantId);
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

            if (! empty($assetPreview['errors'])) {
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

            if (! empty($assetPreview['warnings'])) {
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

        if (! $category) {
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
            if (! isset($fieldMap[$fieldKey])) {
                $result['errors'][] = "Field '{$fieldKey}' not found in schema";

                continue;
            }

            $field = $fieldMap[$fieldKey];

            // Check if field is user-editable
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (! $fieldDef || ! ($fieldDef->is_user_editable ?? true)) {
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

                if (! $canEdit) {
                    $result['warnings'][] = "You don't have permission to edit field '{$fieldKey}'";

                    continue; // Skip this field
                }
            }

            // Get current value
            $oldValue = $currentMetadata[$fieldKey] ?? null;

            // Remove: strip listed tags only (tags field); payload is tags to remove, not the new field value
            if ($operationType === 'remove') {
                if ($fieldKey !== 'tags' || ($fieldDef->type ?? '') !== 'multiselect') {
                    $result['errors'][] = 'Remove is only supported for the Tags field.';

                    continue;
                }

                $tenant = Tenant::find($tenantId);
                if (! $tenant) {
                    $result['errors'][] = 'Tenant not found';

                    continue;
                }

                $toRemovePayload = is_array($newValue) ? $newValue : [];
                $canonicalRemove = [];
                foreach ($toRemovePayload as $raw) {
                    $c = $this->tagNormalizationService->normalize((string) $raw, $tenant);
                    if ($c !== null) {
                        $canonicalRemove[] = $c;
                    }
                }
                $canonicalRemove = array_values(array_unique($canonicalRemove));
                if ($canonicalRemove === []) {
                    $result['errors'][] = 'No valid tags to remove.';

                    continue;
                }

                $oldArr = is_array($oldValue) ? $oldValue : ($oldValue !== null && $oldValue !== '' ? [$oldValue] : []);
                $removeSet = array_flip($canonicalRemove);
                $wouldRemove = false;
                foreach ($oldArr as $tag) {
                    $cn = $this->tagNormalizationService->normalize((string) $tag, $tenant);
                    if ($cn !== null && isset($removeSet[$cn])) {
                        $wouldRemove = true;

                        break;
                    }
                }
                if (! $wouldRemove) {
                    $result['warnings'][] = 'None of the selected tags are on this asset.';

                    continue;
                }

                $newValue = [];
                foreach ($oldArr as $tag) {
                    $cn = $this->tagNormalizationService->normalize((string) $tag, $tenant);
                    if ($cn === null || ! isset($removeSet[$cn])) {
                        $newValue[] = $tag;
                    }
                }
                $newValue = array_values(array_unique($newValue));
            }

            // Handle operation type
            if ($operationType === 'clear') {
                $newValue = null;
            }

            // Add: merge multiselect (e.g. tags) with existing so preview matches execute
            if ($operationType === 'add' && ($fieldDef->type ?? '') === 'multiselect' && $newValue !== null) {
                $oldArr = is_array($oldValue) ? $oldValue : ($oldValue ? [$oldValue] : []);
                $incoming = is_array($newValue) ? $newValue : ($newValue ? [$newValue] : []);
                $newValue = array_values(array_unique(array_merge($oldArr, $incoming)));
            }

            // Validate value
            if ($newValue !== null && ! $this->validateValue($fieldDef, $newValue)) {
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
     * @param  string|null  $userRole  Optional user role for permission checks
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
        if (! in_array($operationType, ['add', 'replace', 'clear', 'remove'], true)) {
            throw new \InvalidArgumentException("Invalid operation type: {$operationType}");
        }

        if ($operationType === 'remove') {
            $this->assertRemoveMetadataPayload($metadataValues, $tenantId);
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

                    \App\Jobs\ScoreAssetComplianceJob::dispatch($asset->id);
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
     * @param  string|null  $userRole  Optional user role for permission checks
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

        if (! $category) {
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
            if (! isset($fieldMap[$fieldKey])) {
                continue; // Skip invalid fields
            }

            $field = $fieldMap[$fieldKey];

            // Check if field is user-editable
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (! $fieldDef || ! ($fieldDef->is_user_editable ?? true)) {
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

                if (! $canEdit) {
                    continue; // Skip fields user cannot edit
                }
            }

            // Remove tags: delete from asset_tags + matching asset_metadata rows (no new metadata rows)
            if ($operationType === 'remove') {
                if ($fieldKey !== 'tags') {
                    continue;
                }

                $tenant = Tenant::find($asset->tenant_id);
                if (! $tenant) {
                    throw new \RuntimeException('Tenant not found');
                }

                $toRemove = is_array($newValue) ? $newValue : [];
                $this->metadataPersistenceService->removeCanonicalTagsFromAsset(
                    $asset,
                    $tenant,
                    $toRemove,
                    (int) $field['field_id']
                );

                continue;
            }

            // Handle operation type
            if ($operationType === 'clear') {
                $newValue = null;
            }

            // Previous value for audit / delta (read before mutating $newValue)
            $oldValue = $currentMetadata[$fieldKey] ?? null;

            // Add + multiselect: only insert values not already present (avoids duplicate rows)
            if ($operationType === 'add' && ($fieldDef->type ?? '') === 'multiselect' && $newValue !== null) {
                $oldArr = is_array($oldValue) ? $oldValue : ($oldValue ? [$oldValue] : []);
                $incoming = is_array($newValue) ? $newValue : ($newValue ? [$newValue] : []);
                $newValue = array_values(array_diff($incoming, $oldArr));
            }

            // Skip if value is empty and operation is not clear
            if ($newValue === null || $newValue === '' || (is_array($newValue) && $newValue === [])) {
                if ($operationType !== 'clear') {
                    continue;
                }
            }

            // Validate value
            if ($newValue !== null && ! $this->validateValue($fieldDef, $newValue)) {
                continue; // Skip invalid values
            }

            $oldValueJson = $oldValue !== null ? json_encode($oldValue) : null;

            // Normalize value
            $normalizedValues = $this->normalizeValue($fieldDef, $newValue);

            $tenant = Tenant::find($asset->tenant_id);
            $brand = \App\Models\Brand::find($asset->brand_id);
            $user = $userId ? \App\Models\User::find($userId) : null;
            $requiresApproval = $tenant && $brand && $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);

            if ($fieldKey === 'tags') {
                $requiresApproval = false;
            }

            // Persist each value
            foreach ($normalizedValues as $value) {
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

            // Tags: grid, drawer, filters, and autocomplete read from `asset_tags`; mirror when approved immediately.
            if ($fieldKey === 'tags' && ! $requiresApproval && $tenant && $normalizedValues !== []) {
                $this->metadataPersistenceService->syncApprovedTagBatchValues($asset, $tenant, $normalizedValues);
            }
        }
    }

    /**
     * Load current approved metadata for an asset.
     *
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

        // Tags grid/search use `asset_tags`; tags may exist only there (no user asset_metadata rows).
        // Include them so bulk "add" merges against the full set the UI shows.
        $mirrorTags = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->pluck('tag')
            ->all();
        if ($mirrorTags !== []) {
            $existing = $result['tags'] ?? [];
            $existing = is_array($existing) ? $existing : ($existing !== null ? [$existing] : []);
            $result['tags'] = array_values(array_unique(array_merge($existing, $mirrorTags), SORT_REGULAR));
        }

        return $result;
    }

    /**
     * Determine asset type.
     */
    protected function determineAssetType(Asset $asset): string
    {
        $type = $asset->type?->value ?? 'image';

        return in_array($type, ['image', 'video', 'document'], true) ? $type : 'image';
    }

    /**
     * Validate value against field type.
     *
     * @param  mixed  $value
     */
    protected function validateValue(object $field, $value): bool
    {
        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            return is_array($value) && ! empty($value);
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
     * @param  mixed  $value
     */
    protected function isValidDate($value): bool
    {
        if (! is_string($value)) {
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
     * @param  mixed  $value
     */
    protected function normalizeValue(object $field, $value): array
    {
        if ($value === null) {
            return [null]; // Clear operation
        }

        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            if (! is_array($value)) {
                return [];
            }

            return array_map(fn ($v) => $v, array_unique($value, SORT_REGULAR));
        }

        return [$value];
    }

    /**
     * Check if two values are equal.
     *
     * @param  mixed  $oldValue
     * @param  mixed  $newValue
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

    /**
     * @param  array<string, mixed>  $metadataValues
     */
    protected function assertRemoveMetadataPayload(array $metadataValues, int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            throw new \InvalidArgumentException('Tenant not found');
        }

        if (count($metadataValues) !== 1 || ! array_key_exists('tags', $metadataValues)) {
            throw new \InvalidArgumentException('Remove applies only to the tags field.');
        }

        $tags = $metadataValues['tags'];
        if (! is_array($tags) || $tags === []) {
            throw new \InvalidArgumentException('Select at least one tag to remove.');
        }

        $canonical = [];
        foreach ($tags as $t) {
            $c = $this->tagNormalizationService->normalize((string) $t, $tenant);
            if ($c !== null) {
                $canonical[] = $c;
            }
        }

        if ($canonical === []) {
            throw new \InvalidArgumentException('No valid tags to remove after normalization.');
        }
    }
}
