<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\MetadataOptionEditGuard;
use Illuminate\Support\Facades\DB;

/**
 * Tenant Metadata Registry Service
 *
 * Phase C4 + Phase G: Service for querying tenant metadata registry
 * (system fields + tenant fields with visibility overrides).
 *
 * ⚠️ PHASE LOCK: Phase G complete. This service is production-locked. Do not refactor.
 *
 * This service provides a unified view of all metadata fields
 * available to a tenant, including system fields and tenant-created fields.
 */
class TenantMetadataRegistryService
{
    public function __construct(
        protected TenantMetadataFieldService $tenantFieldService,
        protected TenantMetadataVisibilityService $visibilityService
    ) {
    }

    /**
     * Get the complete metadata registry for a tenant.
     *
     * Returns:
     * - System fields (read-only) with effective visibility
     * - Tenant fields (editable) with visibility flags
     *
     * @param Tenant $tenant
     * @return array
     */
    public function getRegistry(Tenant $tenant): array
    {
        // Get system fields
        $systemFields = $this->getSystemFields($tenant);

        // Get tenant fields
        $tenantFields = $this->getTenantFields($tenant);

        return [
            'system_fields' => $systemFields,
            'tenant_fields' => $tenantFields,
        ];
    }

    /**
     * Get system metadata fields with effective visibility for tenant.
     *
     * @param Tenant $tenant
     * @return array
     */
    protected function getSystemFields(Tenant $tenant): array
    {
        // Query system metadata fields
        // Exclude dimensions - it's file info, not metadata, and shouldn't appear in metadata management UI
        // Exclude archived (system fields are never archived, but defensive)
        $selectColumns = [
            'id', 'key', 'system_label', 'type', 'applies_to', 'population_mode',
            'show_on_upload', 'show_on_edit', 'show_in_filters', 'readonly', 'group_key',
            'is_filterable', 'is_user_editable', 'is_ai_trainable', 'is_internal_only', 'is_primary',
            'ai_eligible',
        ];
        $selectColumns[] = 'display_widget';
        $fields = DB::table('metadata_fields')
            ->where('scope', 'system')
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->where('key', '!=', 'dimensions') // Dimensions is file info, not metadata
            ->select($selectColumns)
            ->orderBy('key')
            ->get();

        $fieldIds = $fields->pluck('id')->toArray();

        // Get tenant visibility overrides for these fields
        $visibilityOverrides = $this->visibilityService->getFieldVisibilityOverrides($tenant, $fieldIds);

        // Get AI-related flags
        $aiRelatedFields = $this->getAiRelatedFields($fieldIds);

        // Build result array
        $result = [];
        foreach ($fields as $field) {
            $fieldId = $field->id;
            $overrides = $visibilityOverrides[$fieldId] ?? null;

            // Calculate effective visibility (system defaults + tenant overrides)
            $effectiveUpload = $this->calculateEffectiveVisibility(
                $field->show_on_upload ?? true,
                $overrides?->is_upload_hidden ?? false
            );
            // C9.2: Use is_edit_hidden for edit visibility, not is_hidden
            // is_hidden is only for category suppression (big toggle)
            $effectiveEdit = $this->calculateEffectiveVisibility(
                $field->show_on_edit ?? true,
                $overrides?->is_edit_hidden ?? false
            );
            $effectiveFilter = $this->calculateEffectiveVisibility(
                $field->show_in_filters ?? true,
                $overrides?->is_filter_hidden ?? false
            );

            // Derive informational flags
            $isFilterOnly = $field->show_in_filters
                && !$field->show_on_edit
                && !$field->show_on_upload;
            $isAiRelated = in_array($fieldId, $aiRelatedFields);
            $isSystemGenerated = ($field->population_mode ?? 'manual') === 'automatic';
            $supportsOverride = ($field->population_mode ?? 'manual') === 'hybrid';
            $optionEditingRestricted = MetadataOptionEditGuard::isRestricted($field);

            $result[] = [
                'id' => $field->id,
                'key' => $field->key,
                'label' => $field->system_label ?: $field->key, // Fallback to key if system_label is empty
                'system_label' => $field->system_label, // Also include for reference
                'field_type' => $field->type,
                'applies_to' => $field->applies_to,
                'population_mode' => $field->population_mode ?? 'manual',
                // System defaults
                'show_on_upload' => (bool) ($field->show_on_upload ?? true),
                'show_on_edit' => (bool) ($field->show_on_edit ?? true),
                'show_in_filters' => (bool) ($field->show_in_filters ?? true),
                'readonly' => (bool) ($field->readonly ?? false),
                'group_key' => $field->group_key,
                'is_filterable' => (bool) $field->is_filterable,
                'is_user_editable' => (bool) $field->is_user_editable,
                'is_ai_trainable' => (bool) $field->is_ai_trainable,
                'is_internal_only' => (bool) $field->is_internal_only,
                'is_primary' => (bool) ($field->is_primary ?? false),
                'ai_eligible' => (bool) ($field->ai_eligible ?? false), // AI eligibility flag
                // Effective visibility (after tenant overrides)
                'effective_show_on_upload' => $effectiveUpload,
                'effective_show_on_edit' => $effectiveEdit,
                'effective_show_in_filters' => $effectiveFilter,
                // Has tenant override
                'has_tenant_override' => $overrides !== null,
                // Product integrity: system fields with custom rendering do not support option editing
                'option_editing_restricted' => $optionEditingRestricted,
                // Derived flags
                'is_filter_only' => $isFilterOnly,
                'is_ai_related' => $isAiRelated,
                'is_system_generated' => $isSystemGenerated,
                'supports_override' => $supportsOverride,
            ];
        }

        return $result;
    }

    /**
     * Get tenant-created metadata fields.
     *
     * @param Tenant $tenant
     * @return array
     */
    protected function getTenantFields(Tenant $tenant): array
    {
        $fields = $this->tenantFieldService->listFieldsByTenant($tenant, true, false);

        // Get visibility overrides for tenant fields
        $fieldIds = array_column($fields, 'id');
        $visibilityOverrides = $this->visibilityService->getFieldVisibilityOverrides($tenant, $fieldIds);

        // Enhance with effective visibility
        foreach ($fields as &$field) {
            $fieldId = $field['id'];
            $overrides = $visibilityOverrides[$fieldId] ?? null;

            // Calculate effective visibility
            $field['effective_show_on_upload'] = $this->calculateEffectiveVisibility(
                $field['show_on_upload'] ?? true,
                $overrides?->is_upload_hidden ?? false
            );
            $field['effective_show_on_edit'] = $this->calculateEffectiveVisibility(
                $field['show_on_edit'] ?? true,
                $overrides?->is_hidden ?? false
            );
            $field['effective_show_in_filters'] = $this->calculateEffectiveVisibility(
                $field['show_in_filters'] ?? true,
                $overrides?->is_filter_hidden ?? false
            );
            // is_primary is stored directly on the field, not in visibility overrides
            $field['is_primary'] = (bool) ($field['is_primary'] ?? false);
            $field['has_tenant_override'] = $overrides !== null;
        }

        return $fields;
    }

    /**
     * Calculate effective visibility after tenant override.
     *
     * @param bool $systemDefault
     * @param bool $isHidden
     * @return bool
     */
    protected function calculateEffectiveVisibility(bool $systemDefault, bool $isHidden): bool
    {
        // If system default is false, it stays false
        // If system default is true but override hides it, it becomes false
        return $systemDefault && !$isHidden;
    }

    /**
     * Get AI-related field IDs (fields with AI candidates).
     *
     * @param array $fieldIds
     * @return array
     */
    protected function getAiRelatedFields(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        return DB::table('asset_metadata_candidates')
            ->whereIn('metadata_field_id', $fieldIds)
            ->where('producer', 'ai')
            ->distinct()
            ->pluck('metadata_field_id')
            ->toArray();
    }
}
