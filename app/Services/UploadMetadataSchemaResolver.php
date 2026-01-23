<?php

namespace App\Services;

use App\Services\MetadataPermissionResolver;
use App\Services\MetadataVisibilityResolver;

/**
 * Upload Metadata Schema Resolver
 *
 * Phase 2 – Step 1: Upload-specific metadata schema resolver.
 *
 * This service adapts the canonical resolved schema from MetadataSchemaResolver
 * into a structure optimized for upload forms.
 *
 * Rules:
 * - Delegates to MetadataSchemaResolver (single source of truth)
 * - Read-only and side-effect free
 * - Never writes to database
 * - Never re-implements inheritance logic
 * - Applies upload-specific filtering only
 *
 * Upload-specific exclusions:
 * - Fields where is_visible = false
 * - Fields where is_upload_visible = false
 * - Fields where type = rating
 * - Fields where is_internal_only = true
 * - Phase C2: Fields suppressed for the category (via MetadataVisibilityResolver)
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 * @see MetadataSchemaResolver
 */
class UploadMetadataSchemaResolver
{
    public function __construct(
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected MetadataPermissionResolver $permissionResolver,
        protected MetadataVisibilityResolver $visibilityResolver
    ) {
    }

    /**
     * Resolve the upload metadata schema for a given context.
     *
     * @param int $tenantId Required tenant ID
     * @param int|null $brandId Optional brand ID
     * @param int $categoryId Required category ID
     * @param string $assetType Required: 'image' | 'video' | 'document'
     * @param string|null $userRole Optional user role for permission checks
     * @return array Upload schema with grouped fields
     */
    public function resolve(
        int $tenantId,
        ?int $brandId,
        int $categoryId,
        string $assetType,
        ?string $userRole = null
    ): array {
        // Delegate to canonical resolver
        $resolvedSchema = $this->metadataSchemaResolver->resolve(
            $tenantId,
            $brandId,
            $categoryId,
            $assetType
        );

        // Load category for visibility filtering (Phase C2)
        $category = \App\Models\Category::find($categoryId);
        
        // Load tenant for tenant-level visibility overrides (Phase C4)
        $tenant = \App\Models\Tenant::find($tenantId);

        // Filter fields for upload-specific rules (includes category suppression via MetadataVisibilityResolver)
        $uploadFields = $this->filterForUpload($resolvedSchema['fields'], $category, $tenant);

        // Add permission flags if user role provided
        if ($userRole !== null) {
            $uploadFields = $this->addPermissionFlags(
                $uploadFields,
                $userRole,
                $tenantId,
                $brandId,
                $categoryId
            );
        }

        // Group fields by group_key
        $grouped = $this->groupFields($uploadFields);

        // Convert to output structure
        return $this->buildOutput($grouped);
    }

    /**
     * Filter resolved fields for upload-specific rules.
     *
     * Excludes fields that are:
     * - Not visible (is_visible = false)
     * - Not upload-visible (is_upload_visible = false)
     * - Rating type
     * - Internal only
     * - Phase B2: Automatically populated fields (population_mode = 'automatic')
     * - Phase B2: Fields explicitly hidden from upload (show_on_upload = false)
     * - Phase C2: Fields suppressed for the category (via MetadataVisibilityResolver)
     * - Phase C4: Fields suppressed by tenant overrides (via MetadataVisibilityResolver)
     *
     * @param array $fields Resolved fields from MetadataSchemaResolver
     * @param \App\Models\Category|null $category Category model for suppression check
     * @param \App\Models\Tenant|null $tenant Tenant model for tenant-level overrides
     * @return array Filtered fields
     */
    protected function filterForUpload(array $fields, ?\App\Models\Category $category = null, ?\App\Models\Tenant $tenant = null): array
    {
        $uploadFields = [];

        foreach ($fields as $field) {
            // Exclude if not visible
            if (!$field['is_visible']) {
                continue;
            }

            // Exclude if not upload-visible
            if (!$field['is_upload_visible']) {
                continue;
            }

            // Exclude rating fields
            if ($field['type'] === 'rating') {
                continue;
            }

            // Exclude internal-only fields
            if ($field['is_internal_only']) {
                continue;
            }

            // Phase B2: Exclude automatically populated fields
            $populationMode = $field['population_mode'] ?? 'manual';
            if ($populationMode === 'automatic') {
                continue;
            }

            // Phase B2: Exclude fields explicitly hidden from upload
            $showOnUpload = $field['show_on_upload'] ?? true;
            if (!$showOnUpload) {
                continue;
            }

            $uploadFields[] = $field;
        }

        // Phase C2: Apply category suppression filtering via centralized resolver
        return $this->visibilityResolver->filterVisibleFields($uploadFields, $category, $tenant);
    }

    /**
     * Group fields by group_key.
     *
     * Fields with null group_key go into "General" group.
     * Groups are returned in a stable order (sorted by group_key).
     * Fields within groups maintain their original order.
     *
     * @param array $fields Filtered upload fields
     * @return array Keyed by group_key
     */
    protected function groupFields(array $fields): array
    {
        $grouped = [];

        foreach ($fields as $field) {
            $groupKey = $field['group_key'] ?? 'general';
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [];
            }
            $grouped[$groupKey][] = $field;
        }

        // Sort groups by key for stable order
        ksort($grouped);

        return $grouped;
    }

    /**
     * Build the final output structure.
     *
     * @param array $grouped Fields grouped by group_key
     * @return array Output structure with groups
     */
    protected function buildOutput(array $grouped): array
    {
        $groups = [];

        foreach ($grouped as $groupKey => $fields) {
            $groups[] = [
                'key' => $groupKey,
                'label' => $this->getGroupLabel($groupKey),
                'fields' => $this->formatFields($fields),
            ];
        }

        return [
            'groups' => $groups,
        ];
    }

    /**
     * Get human-readable label for a group key.
     *
     * @param string $groupKey
     * @return string
     */
    protected function getGroupLabel(string $groupKey): string
    {
        // Map common group keys to labels
        $labelMap = [
            'general' => 'General',
            'creative' => 'Creative',
            'technical' => 'Technical',
            'commercial' => 'Commercial',
            'legal' => 'Legal / Rights',
            'legal_rights' => 'Legal / Rights',
            'ai' => 'AI / System',
            'ai_system' => 'AI / System',
        ];

        // Use mapped label if available, otherwise humanize the key
        if (isset($labelMap[strtolower($groupKey)])) {
            return $labelMap[strtolower($groupKey)];
        }

        // Fallback: humanize the group key
        return ucwords(str_replace(['_', '-'], ' ', $groupKey));
    }

    /**
     * Add permission flags to fields.
     *
     * Phase 4: Adds can_edit flag based on user role and permissions.
     * Phase 5: Also respects is_user_editable flag from metadata_fields.
     *
     * @param array $fields
     * @param string $userRole
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array Fields with can_edit flags
     */
    protected function addPermissionFlags(
        array $fields,
        string $userRole,
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        if (empty($fields)) {
            return $fields;
        }

        // Get permissions for all fields at once
        $fieldIds = array_column($fields, 'field_id');
        $permissions = $this->permissionResolver->canEditMultiple(
            $fieldIds,
            $userRole,
            $tenantId,
            $brandId,
            $categoryId
        );

        // Load field properties from database
        $fieldData = \Illuminate\Support\Facades\DB::table('metadata_fields')
            ->whereIn('id', $fieldIds)
            ->select('id', 'is_user_editable', 'population_mode', 'readonly')
            ->get()
            ->keyBy('id');

        // Add can_edit flag to each field
        // Field is editable only if:
        // 1. Permission resolver says user can edit (Phase 4) - owners/admins bypass this
        // 2. Field is marked as user-editable in database (Phase 5)
        // Exception: System-locked fields (automatic + readonly) are never editable by users
        foreach ($fields as &$field) {
            $fieldId = $field['field_id'];
            $fieldInfo = $fieldData[$fieldId] ?? null;
            
            $hasPermission = $permissions[$fieldId] ?? false;
            $isUserEditable = $fieldInfo ? ($fieldInfo->is_user_editable ?? true) : true;
            
            // Check if field is system-locked (automatic + readonly)
            // These fields are automatically populated by the system and users cannot edit them
            $isSystemLocked = $fieldInfo 
                && ($fieldInfo->population_mode ?? null) === 'automatic' 
                && ($fieldInfo->readonly ?? false) === true;
            
            // Field is editable if:
            // - Has permission AND is user-editable AND not system-locked
            $field['can_edit'] = ($hasPermission && $isUserEditable && !$isSystemLocked);
        }

        return $fields;
    }

    /**
     * Format fields for output.
     *
     * Removes visibility flags and includes only upload-relevant data.
     *
     * @param array $fields Fields in a group
     * @return array Formatted fields
     */
    protected function formatFields(array $fields): array
    {
        $formatted = [];

        foreach ($fields as $field) {
            $formatted[] = [
                'field_id' => $field['field_id'],
                'key' => $field['key'],
                'display_label' => $field['display_label'],
                'type' => $field['type'],
                'is_required' => false, // Stub for future implementation
                'can_edit' => $field['can_edit'] ?? true, // Default to true if not set (backward compatibility)
                'options' => $field['options'] ?? [],
            ];
        }

        return $formatted;
    }
}
