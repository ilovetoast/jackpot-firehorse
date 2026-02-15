<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tenant Metadata Field Service
 *
 * Phase C3: Manages tenant-scoped custom metadata fields.
 *
 * This service handles creation, validation, and immutability enforcement
 * for tenant-created metadata fields.
 *
 * Rules:
 * - Fields must be namespaced with 'custom__' prefix
 * - Key must be unique per tenant
 * - Plan limits are enforced
 * - Immutability: key and type cannot change after field has values
 * - Soft disable only (is_active flag)
 *
 * @see docs/PHASE_C_METADATA_GOVERNANCE.md
 */
class TenantMetadataFieldService
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Allowed field types for tenant fields.
     */
    protected const ALLOWED_TYPES = [
        'text',
        'textarea',
        'select',
        'multiselect',
        'number',
        'boolean',
        'date',
    ];

    /**
     * Create a new tenant metadata field.
     *
     * @param Tenant $tenant
     * @param array $data Field data:
     *   - key: string (must start with 'custom__')
     *   - system_label: string
     *   - type: string (one of ALLOWED_TYPES)
     *   - applies_to: string ('image', 'video', 'document', 'all')
     *   - options: array (required for select/multiselect)
     *   - is_filterable: bool (default: false)
     *   - show_on_upload: bool (default: true)
     *   - show_on_edit: bool (default: true)
     *   - show_in_filters: bool (default: true)
     *   - group_key: string|null (default: 'custom')
     * @return int Field ID
     * @throws ValidationException
     * @throws \Exception
     */
    public function createField(Tenant $tenant, array $data): int
    {
        // Validate permissions (checked in controller, but double-check here)
        // This service assumes permission checks are done upstream

        // Validate key format
        if (!isset($data['key']) || !str_starts_with($data['key'], 'custom__')) {
            throw ValidationException::withMessages([
                'key' => ['Field key must start with "custom__" prefix.'],
            ]);
        }

        // Validate key format (alphanumeric, underscores, dots only after custom__)
        $keyPattern = '/^custom__[a-z0-9_]+$/';
        if (!preg_match($keyPattern, $data['key'])) {
            throw ValidationException::withMessages([
                'key' => ['Field key must start with "custom__" and contain only lowercase letters, numbers, and underscores.'],
            ]);
        }

        // Check plan limit
        $this->checkPlanLimit($tenant);

        // Check if key already exists for this tenant
        $existing = DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('key', $data['key'])
            ->where('scope', 'tenant')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'key' => ['A field with this key already exists for this tenant.'],
            ]);
        }

        // Also check system fields to prevent conflicts
        $systemField = DB::table('metadata_fields')
            ->where('key', $data['key'])
            ->where('scope', 'system')
            ->first();

        if ($systemField) {
            throw ValidationException::withMessages([
                'key' => ['This key conflicts with a system field.'],
            ]);
        }

        // Validate field type
        if (!isset($data['type']) || !in_array($data['type'], self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => ['Invalid field type. Allowed types: ' . implode(', ', self::ALLOWED_TYPES)],
            ]);
        }

        // Validate options for select/multiselect
        if (in_array($data['type'], ['select', 'multiselect'], true)) {
            if (empty($data['options']) || !is_array($data['options'])) {
                throw ValidationException::withMessages([
                    'options' => ['Options are required for select and multiselect fields.'],
                ]);
            }

            // Validate options structure
            foreach ($data['options'] as $option) {
                if (!is_array($option) || !isset($option['value']) || !isset($option['label'])) {
                    throw ValidationException::withMessages([
                        'options' => ['Each option must have "value" and "label" keys.'],
                    ]);
                }
            }
        }

        // Validate applies_to
        $allowedAppliesTo = ['image', 'video', 'document', 'all'];
        if (!isset($data['applies_to']) || !in_array($data['applies_to'], $allowedAppliesTo, true)) {
            throw ValidationException::withMessages([
                'applies_to' => ['Invalid applies_to value. Must be one of: ' . implode(', ', $allowedAppliesTo)],
            ]);
        }

        // Determine ai_eligible based on field type and options
        // AI suggestions are only allowed for select/multiselect fields with options
        $aiEligible = false;
        if (in_array($data['type'], ['select', 'multiselect'], true)) {
            // Only enable AI if options are provided
            // If no options exist, AI suggestions are disabled to prevent free-text hallucinations
            $aiEligible = !empty($data['options']) && ($data['ai_eligible'] ?? false);
        }

        // Build field data
        $fieldData = [
            'key' => $data['key'],
            'system_label' => $data['system_label'] ?? $data['key'],
            'type' => $data['type'],
            'applies_to' => 'all', // Default to all asset types (category selection handles visibility)
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_filterable' => $data['is_filterable'] ?? false,
            'is_user_editable' => true, // Tenant fields are always user-editable
            'is_ai_trainable' => false, // Tenant fields cannot be AI-trained (Phase C3)
            'ai_eligible' => $aiEligible, // AI suggestions enabled only if select/multiselect with options
            'is_upload_visible' => $data['show_on_upload'] ?? true,
            'is_internal_only' => false, // Tenant fields are never internal-only
            'group_key' => $data['group_key'] ?? 'custom',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'population_mode' => 'manual', // Tenant fields are always manual
            'show_on_upload' => $data['show_on_upload'] ?? true,
            'show_on_edit' => $data['show_on_edit'] ?? true,
            'show_in_filters' => $data['show_in_filters'] ?? true,
            'readonly' => false, // Tenant fields are never readonly
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Create field
        $fieldId = DB::table('metadata_fields')->insertGetId($fieldData);

        // Create options if provided
        if (!empty($data['options']) && in_array($data['type'], ['select', 'multiselect'], true)) {
            foreach ($data['options'] as $option) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $fieldId,
                    'value' => $option['value'],
                    'system_label' => $option['label'],
                    'is_system' => false, // Tenant-created options
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Enable field for selected categories; suppress for all others
        $visibilityService = app(\App\Services\TenantMetadataVisibilityService::class);
        $selectedIds = array_map('intval', $data['selectedCategories'] ?? []);
        $allCategories = \App\Models\Category::where('tenant_id', $tenant->id)->get();
        foreach ($allCategories as $category) {
            try {
                if (in_array((int) $category->id, $selectedIds, true)) {
                    $visibilityService->unsuppressForCategory($tenant, $fieldId, $category);
                } else {
                    $visibilityService->suppressForCategory($tenant, $fieldId, $category);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to set field visibility for category', [
                    'field_id' => $fieldId,
                    'category_id' => $category->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Audit log
        Log::info('Tenant metadata field created', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'field_key' => $data['key'],
            'field_type' => $data['type'],
            'categories' => $data['selectedCategories'] ?? [],
        ]);

        return $fieldId;
    }

    /**
     * Update a tenant metadata field.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @param array $data Update data (key and type cannot be changed)
     * @return bool
     * @throws ValidationException
     * @throws \Exception
     */
    public function updateField(Tenant $tenant, int $fieldId, array $data): bool
    {
        // Verify field belongs to tenant
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$field) {
            throw new \InvalidArgumentException("Field {$fieldId} does not exist or does not belong to tenant {$tenant->id}.");
        }

        // Check if field has values (immutability check)
        $hasValues = DB::table('asset_metadata')
            ->where('metadata_field_id', $fieldId)
            ->exists();

        // Key and type cannot be changed if field has values
        if ($hasValues && (isset($data['key']) || isset($data['type']))) {
            throw ValidationException::withMessages([
                'key' => ['Field key and type cannot be changed after the field has been used.'],
            ]);
        }

        // Update allowed fields
        $updateData = [];
        if (isset($data['system_label'])) {
            $updateData['system_label'] = $data['system_label'];
        }
        if (isset($data['applies_to'])) {
            $updateData['applies_to'] = $data['applies_to'];
        }
        if (isset($data['is_filterable'])) {
            $updateData['is_filterable'] = $data['is_filterable'];
        }
        if (isset($data['show_on_upload'])) {
            $updateData['show_on_upload'] = $data['show_on_upload'];
        }
        if (isset($data['show_on_edit'])) {
            $updateData['show_on_edit'] = $data['show_on_edit'];
        }
        if (isset($data['show_in_filters'])) {
            $updateData['show_in_filters'] = $data['show_in_filters'];
        }
        if (isset($data['group_key'])) {
            $updateData['group_key'] = $data['group_key'];
        }
        if (isset($data['ai_eligible'])) {
            $updateData['ai_eligible'] = $data['ai_eligible'];
        }

        $updateData['updated_at'] = now();

        // Update options if provided
        if (isset($data['options']) && is_array($data['options'])) {
            // Delete existing options
            DB::table('metadata_options')
                ->where('metadata_field_id', $fieldId)
                ->delete();

            // Insert new options
            foreach ($data['options'] as $option) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $fieldId,
                    'value' => $option['value'],
                    'system_label' => $option['label'],
                    'is_system' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->update($updateData);

        Log::info('Tenant metadata field updated', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'field_key' => $field->key,
        ]);

        return true;
    }

    /**
     * Get a single tenant metadata field.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @return array|null
     */
    public function getField(Tenant $tenant, int $fieldId): ?array
    {
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$field) {
            return null;
        }

        // Load options
        $options = DB::table('metadata_options')
            ->where('metadata_field_id', $fieldId)
            ->orderBy('id')
            ->get()
            ->map(fn ($opt) => [
                'value' => $opt->value,
                'label' => $opt->system_label,
            ])
            ->toArray();

        return [
            'id' => $field->id,
            'key' => $field->key,
            'label' => $field->system_label ?: $field->key, // Use system_label as label, fallback to key
            'system_label' => $field->system_label,
            'type' => $field->type,
            'applies_to' => $field->applies_to,
            'options' => $options,
            'ai_eligible' => (bool) $field->ai_eligible,
            'is_filterable' => (bool) $field->is_filterable,
            'show_on_upload' => (bool) $field->show_on_upload,
            'show_on_edit' => (bool) $field->show_on_edit,
            'show_in_filters' => (bool) $field->show_in_filters,
            'group_key' => $field->group_key,
            'is_active' => (bool) $field->is_active,
            'scope' => 'tenant',
        ];
    }

    /**
     * Disable a tenant metadata field (soft disable).
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @return bool
     * @throws \Exception
     */
    public function disableField(Tenant $tenant, int $fieldId): bool
    {
        // Verify field belongs to tenant
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$field) {
            throw new \InvalidArgumentException("Field {$fieldId} does not exist or does not belong to tenant {$tenant->id}.");
        }

        // Soft disable
        DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        // Audit log
        Log::info('Tenant metadata field disabled', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'field_key' => $field->key,
        ]);

        return true;
    }

    /**
     * Re-enable a tenant metadata field.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @return bool
     * @throws \Exception
     */
    public function enableField(Tenant $tenant, int $fieldId): bool
    {
        // Verify field belongs to tenant
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$field) {
            throw new \InvalidArgumentException("Field {$fieldId} does not exist or does not belong to tenant {$tenant->id}.");
        }

        // Re-enable
        DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

        // Audit log
        Log::info('Tenant metadata field enabled', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'field_key' => $field->key,
        ]);

        return true;
    }

    /**
     * List all tenant metadata fields.
     *
     * @param Tenant $tenant
     * @param bool $includeInactive Include inactive fields
     * @return array
     */
    public function listFieldsByTenant(Tenant $tenant, bool $includeInactive = false): array
    {
        $query = DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->whereNull('deprecated_at')
            ->orderBy('created_at', 'desc');

        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        $fields = $query->get();

        // Load options for each field
        $fieldIds = $fields->pluck('id')->toArray();
        $options = [];
        if (!empty($fieldIds)) {
            $optionsData = DB::table('metadata_options')
                ->whereIn('metadata_field_id', $fieldIds)
                ->orderBy('metadata_field_id')
                ->orderBy('id')
                ->get();

            foreach ($optionsData as $option) {
                if (!isset($options[$option->metadata_field_id])) {
                    $options[$option->metadata_field_id] = [];
                }
                $options[$option->metadata_field_id][] = [
                    'value' => $option->value,
                    'label' => $option->system_label,
                ];
            }
        }

        // Build result
        $result = [];
        foreach ($fields as $field) {
            $result[] = [
                'id' => $field->id,
                'key' => $field->key,
                'system_label' => $field->system_label,
                'type' => $field->type,
                'applies_to' => $field->applies_to,
                'is_filterable' => (bool) $field->is_filterable,
                'show_on_upload' => (bool) ($field->show_on_upload ?? true),
                'show_on_edit' => (bool) ($field->show_on_edit ?? true),
                'show_in_filters' => (bool) ($field->show_in_filters ?? true),
                'is_primary' => (bool) ($field->is_primary ?? false),
                'group_key' => $field->group_key,
                'is_active' => (bool) ($field->is_active ?? true),
                'options' => $options[$field->id] ?? [],
                'created_at' => $field->created_at,
                'updated_at' => $field->updated_at,
            ];
        }

        return $result;
    }

    /**
     * Check if a field can be modified (key or type change).
     *
     * Fields with existing values cannot have their key or type changed.
     *
     * @param int $fieldId
     * @return bool True if field has values, false otherwise
     */
    public function fieldHasValues(int $fieldId): bool
    {
        $count = DB::table('asset_metadata')
            ->where('metadata_field_id', $fieldId)
            ->whereNotNull('approved_at')
            ->count();

        return $count > 0;
    }

    /**
     * Check plan limit for custom metadata fields.
     *
     * @param Tenant $tenant
     * @return void
     * @throws PlanLimitExceededException
     * @throws \Exception
     */
    protected function checkPlanLimit(Tenant $tenant): void
    {
        $limits = $this->planService->getPlanLimits($tenant);
        $maxFields = $limits['max_custom_metadata_fields'] ?? 0;

        if ($maxFields === 0) {
            throw new PlanLimitExceededException(
                'custom_metadata_fields',
                0,
                0,
                'Your plan does not allow custom metadata fields. Please upgrade your plan.'
            );
        }

        // Count active tenant fields
        $currentCount = DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->count();

        if ($currentCount >= $maxFields) {
            throw new PlanLimitExceededException(
                'custom_metadata_fields',
                $currentCount,
                $maxFields,
                "Plan limit exceeded. You have {$currentCount} of {$maxFields} custom metadata fields. Please upgrade your plan to create more fields."
            );
        }
    }
}
