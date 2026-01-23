<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Metadata Permission Resolver
 *
 * Phase 4: Read-only resolver for metadata field edit permissions.
 *
 * This service computes whether a user can edit a metadata field
 * based on their role and the permission overrides at different scopes.
 *
 * Rules:
 * - Deterministic and side-effect free
 * - Never mutates data
 * - Never creates missing rows
 * - Default is false (no permission unless explicitly granted)
 *
 * Inheritance order (lowest → highest priority):
 * 1. System default (no permission row = false)
 * 2. Tenant-level permission
 * 3. Brand-level permission
 * 4. Category-level permission
 *
 * Last override wins.
 */
class MetadataPermissionResolver
{
    /**
     * Check if a user can edit a metadata field.
     *
     * @param int $metadataFieldId
     * @param string $role User's role (e.g., 'owner', 'admin', 'editor', 'viewer', 'member')
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return bool True if user can edit, false otherwise
     */
    public function canEdit(
        int $metadataFieldId,
        string $role,
        int $tenantId,
        ?int $brandId = null,
        ?int $categoryId = null
    ): bool {
        // Owners and admins have full access to edit all fields (except system-locked automatic fields)
        // Check if field is system-locked (automatic + readonly)
        $field = \Illuminate\Support\Facades\DB::table('metadata_fields')
            ->where('id', $metadataFieldId)
            ->first();
        
        if ($field) {
            // System-locked fields: automatic population AND readonly (users cannot edit, system fills these)
            $isSystemLocked = ($field->population_mode === 'automatic' && $field->readonly === true);
            
            // Owners/admins can edit everything except system-locked fields
            if (in_array($role, ['owner', 'admin']) && !$isSystemLocked) {
                return true;
            }
        } else {
            // Field doesn't exist, but owners/admins should still have access
            if (in_array($role, ['owner', 'admin'])) {
                return true;
            }
        }
        
        // Load permission overrides in inheritance order
        $permission = $this->loadPermission(
            $metadataFieldId,
            $role,
            $tenantId,
            $brandId,
            $categoryId
        );

        // Default is false if no permission row exists (for non-owner/admin roles)
        return $permission['can_edit'] ?? false;
    }

    /**
     * Load permission override for a field/role/scope combination.
     *
     * Returns the highest priority permission override.
     *
     * Inheritance order: tenant < brand < category (category wins)
     *
     * @param int $metadataFieldId
     * @param string $role
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array|null Permission data with 'can_edit' flag, or null if no override exists
     */
    protected function loadPermission(
        int $metadataFieldId,
        string $role,
        int $tenantId,
        ?int $brandId = null,
        ?int $categoryId = null
    ): ?array {
        // Build OR conditions for all applicable scopes
        $query = DB::table('metadata_field_permissions')
            ->where('metadata_field_id', $metadataFieldId)
            ->where('role', $role)
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId, $categoryId) {
                // Tenant-level: brand_id IS NULL AND category_id IS NULL
                $q->where(function ($subQ) {
                    $subQ->whereNull('brand_id')->whereNull('category_id');
                });

                // Brand-level: brand_id = $brandId AND category_id IS NULL
                if ($brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId) {
                        $subQ->where('brand_id', $brandId)->whereNull('category_id');
                    });
                }

                // Category-level: brand_id = $brandId AND category_id = $categoryId
                if ($categoryId !== null && $brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId, $categoryId) {
                        $subQ->where('brand_id', $brandId)->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByRaw('
                CASE
                    WHEN category_id IS NOT NULL THEN 3
                    WHEN brand_id IS NOT NULL THEN 2
                    ELSE 1
                END DESC
            ')
            ->first();

        if (!$query) {
            return null; // No permission override exists
        }

        return [
            'can_edit' => (bool) $query->can_edit,
        ];
    }

    /**
     * Check permissions for multiple fields at once.
     *
     * @param array $metadataFieldIds
     * @param string $role
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array Keyed by field_id, value is boolean (can_edit)
     */
    public function canEditMultiple(
        array $metadataFieldIds,
        string $role,
        int $tenantId,
        ?int $brandId = null,
        ?int $categoryId = null
    ): array {
        if (empty($metadataFieldIds)) {
            return [];
        }

        // Owners and admins have full access (except system-locked fields)
        $isOwnerOrAdmin = in_array($role, ['owner', 'admin']);
        
        // Load field data to check for system-locked fields
        $fields = DB::table('metadata_fields')
            ->whereIn('id', $metadataFieldIds)
            ->get()
            ->keyBy('id');

        // Load all permissions in one query
        $query = DB::table('metadata_field_permissions')
            ->whereIn('metadata_field_id', $metadataFieldIds)
            ->where('role', $role)
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId, $categoryId) {
                // Tenant-level
                $q->where(function ($subQ) {
                    $subQ->whereNull('brand_id')->whereNull('category_id');
                });

                // Brand-level
                if ($brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId) {
                        $subQ->where('brand_id', $brandId)->whereNull('category_id');
                    });
                }

                // Category-level
                if ($categoryId !== null && $brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId, $categoryId) {
                        $subQ->where('brand_id', $brandId)->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByRaw('
                CASE
                    WHEN category_id IS NOT NULL THEN 3
                    WHEN brand_id IS NOT NULL THEN 2
                    ELSE 1
                END DESC
            ')
            ->get();

        // Group by field_id and take the first (highest priority) permission
        $results = [];
        foreach ($query as $row) {
            // Only set if not already set (first = highest priority wins)
            if (!isset($results[$row->metadata_field_id])) {
                $results[$row->metadata_field_id] = (bool) $row->can_edit;
            }
        }

        // Fill in defaults for fields with no permission override
        foreach ($metadataFieldIds as $fieldId) {
            if (!isset($results[$fieldId])) {
                if ($isOwnerOrAdmin) {
                    // Owners/admins can edit all fields except system-locked ones
                    $field = $fields[$fieldId] ?? null;
                    $isSystemLocked = $field && ($field->population_mode === 'automatic' && $field->readonly === true);
                    $results[$fieldId] = !$isSystemLocked;
                } else {
                    // Non-owner/admin: default to false if no permission row exists
                    $results[$fieldId] = false;
                }
            }
        }

        return $results;
    }
}
