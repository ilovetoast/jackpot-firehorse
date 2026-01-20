<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tenant;

/**
 * Metadata Visibility Resolver
 *
 * Phase C2: Centralized visibility resolution for metadata fields.
 * Phase C4: Extended to respect tenant-level visibility overrides.
 *
 * This service provides a single source of truth for filtering metadata fields
 * based on category suppression rules and tenant visibility overrides.
 *
 * Rules:
 * - Filters out fields suppressed for the given category (system-level)
 * - Filters out fields suppressed by tenant overrides
 * - Returns all fields if no category is provided
 * - Returns all fields if category has no system_category_id
 * - No-op if no suppression rules exist
 *
 * Usage:
 * - UploadMetadataSchemaResolver: Filter fields for upload forms
 * - MetadataFilterService: Filter fields for filter UI
 * - AssetMetadataController: Filter fields for edit forms
 */
class MetadataVisibilityResolver
{
    public function __construct(
        protected SystemMetadataVisibilityService $visibilityService,
        protected ?TenantMetadataVisibilityService $tenantVisibilityService = null
    ) {
        // Lazy load tenant visibility service to avoid circular dependencies
        if ($this->tenantVisibilityService === null) {
            $this->tenantVisibilityService = app(TenantMetadataVisibilityService::class);
        }
    }

    /**
     * Filter fields to exclude those suppressed for the given category.
     *
     * Phase C2: Centralized visibility filtering.
     * Phase C4: Extended to respect tenant-level visibility overrides.
     *
     * @param array $fields Array of field definitions (must have 'field_id' key)
     * @param Category|null $category Category model (will extract system_category_id)
     * @param Tenant|null $tenant Optional tenant for tenant-level overrides
     * @return array Filtered fields array
     */
    public function filterVisibleFields(array $fields, ?Category $category = null, ?Tenant $tenant = null): array
    {
        // Filter out suppressed fields
        $visibleFields = [];
        foreach ($fields as $field) {
            // Field must have field_id to check suppression
            if (!isset($field['field_id'])) {
                // If no field_id, include field (can't check suppression)
                $visibleFields[] = $field;
                continue;
            }

            // Phase C4: Check tenant-level visibility first
            if ($tenant !== null) {
                $isTenantVisible = $this->tenantVisibilityService->isVisibleForCategory(
                    $tenant,
                    $field['field_id'],
                    $category
                );

                if (!$isTenantVisible) {
                    // Suppressed by tenant override, skip field
                    continue;
                }
            }

            // Phase C2: Check system-level category suppression
            if ($category !== null) {
                $systemCategoryId = $category->system_category_id;

                // If category has system_category_id, check system suppression
                if ($systemCategoryId !== null) {
                    $isSystemVisible = $this->visibilityService->isVisibleForCategory(
                        $field['field_id'],
                        $systemCategoryId
                    );

                    if (!$isSystemVisible) {
                        // Suppressed by system, skip field
                        continue;
                    }
                }
            }

            // Field passed all visibility checks
            $visibleFields[] = $field;
        }

        return $visibleFields;
    }

    /**
     * Filter fields to exclude those suppressed for the given system category ID.
     *
     * Alternative method that accepts system_category_id directly.
     *
     * @param array $fields Array of field definitions (must have 'field_id' key)
     * @param int|null $systemCategoryId System category ID
     * @return array Filtered fields array
     */
    public function filterVisibleFieldsBySystemCategory(array $fields, ?int $systemCategoryId = null): array
    {
        // If no system category ID, return all fields (no suppression)
        if ($systemCategoryId === null) {
            return $fields;
        }

        // Filter out suppressed fields
        $visibleFields = [];
        foreach ($fields as $field) {
            // Field must have field_id to check suppression
            if (!isset($field['field_id'])) {
                // If no field_id, include field (can't check suppression)
                $visibleFields[] = $field;
                continue;
            }

            // Check if field is visible for this category
            $isVisible = $this->visibilityService->isVisibleForCategory(
                $field['field_id'],
                $systemCategoryId
            );

            if ($isVisible) {
                $visibleFields[] = $field;
            }
        }

        return $visibleFields;
    }

    /**
     * Check if a single field is visible for the given category.
     *
     * Phase C4: Extended to respect tenant-level visibility overrides.
     *
     * @param array $field Field definition (must have 'field_id' key)
     * @param Category|null $category Category model
     * @param Tenant|null $tenant Optional tenant for tenant-level overrides
     * @return bool True if visible, false if suppressed
     */
    public function isFieldVisible(array $field, ?Category $category = null, ?Tenant $tenant = null): bool
    {
        // Field must have field_id to check suppression
        if (!isset($field['field_id'])) {
            // If no field_id, assume visible (can't check suppression)
            return true;
        }

        // Phase C4: Check tenant-level visibility first
        if ($tenant !== null) {
            $isTenantVisible = $this->tenantVisibilityService->isVisibleForCategory(
                $tenant,
                $field['field_id'],
                $category
            );

            if (!$isTenantVisible) {
                // Suppressed by tenant override
                return false;
            }
        }

        // Phase C2: Check system-level category suppression
        if ($category !== null) {
            $systemCategoryId = $category->system_category_id;

            // If category has system_category_id, check system suppression
            if ($systemCategoryId !== null) {
                $isSystemVisible = $this->visibilityService->isVisibleForCategory(
                    $field['field_id'],
                    $systemCategoryId
                );

                if (!$isSystemVisible) {
                    // Suppressed by system
                    return false;
                }
            }
        }

        // Field passed all visibility checks
        return true;
    }
}
