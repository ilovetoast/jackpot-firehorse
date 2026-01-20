<?php

namespace App\Services;

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

        // Build field data
        $fieldData = [
            'key' => $data['key'],
            'system_label' => $data['system_label'] ?? $data['key'],
            'type' => $data['type'],
            'applies_to' => $data['applies_to'],
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_filterable' => $data['is_filterable'] ?? false,
            'is_user_editable' => true, // Tenant fields are always user-editable
            'is_ai_trainable' => false, // Tenant fields cannot be AI-trained (Phase C3)
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

        // Audit log
        Log::info('Tenant metadata field created', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'field_key' => $data['key'],
            'field_type' => $data['type'],
        ]);

        return $fieldId;
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
     * @throws \Exception
     */
    protected function checkPlanLimit(Tenant $tenant): void
    {
        $limits = $this->planService->getPlanLimits($tenant);
        $maxFields = $limits['max_custom_metadata_fields'] ?? 0;

        if ($maxFields === 0) {
            throw new \Exception('Your plan does not allow custom metadata fields.');
        }

        // Count active tenant fields
        $currentCount = DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->count();

        if ($currentCount >= $maxFields) {
            throw new \Exception("Plan limit exceeded. Maximum {$maxFields} custom metadata fields allowed.");
        }
    }
}
