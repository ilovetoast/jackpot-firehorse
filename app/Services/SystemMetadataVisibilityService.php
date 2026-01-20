<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * System Metadata Visibility Service
 *
 * Phase C1, Step 2: Manages system-level category suppression for metadata fields.
 *
 * This service handles category-specific visibility rules for system metadata fields
 * without modifying field definitions or ownership.
 *
 * Rules:
 * - System-scoped only (no tenant/brand logic)
 * - References system_category templates
 * - Absence of row = visible by default
 * - is_visible = false = suppressed
 * - All changes are auditable and reversible
 */
class SystemMetadataVisibilityService
{
    /**
     * Get category visibility rules for a metadata field.
     *
     * @param int $fieldId Metadata field ID
     * @return array Array of visibility rules keyed by system_category_id
     */
    public function getFieldCategoryVisibility(int $fieldId): array
    {
        $rules = DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->get()
            ->keyBy('system_category_id')
            ->map(function ($rule) {
                return [
                    'system_category_id' => $rule->system_category_id,
                    'is_visible' => (bool) $rule->is_visible,
                ];
            })
            ->toArray();

        return $rules;
    }

    /**
     * Get all categories with their visibility status for a field.
     *
     * @param int $fieldId Metadata field ID
     * @return array Array of categories with visibility status
     */
    public function getFieldCategories(int $fieldId): array
    {
        // Get all system categories
        $allCategories = DB::table('system_categories')
            ->select('id', 'name', 'slug', 'asset_type')
            ->orderBy('name')
            ->get();

        // Get existing suppression rules
        $suppressionRules = DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('is_visible', false)
            ->pluck('system_category_id')
            ->toArray();

        $result = [];
        foreach ($allCategories as $category) {
            $isSuppressed = in_array($category->id, $suppressionRules);
            
            $result[] = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'asset_type' => $category->asset_type,
                'is_visible' => !$isSuppressed, // Absence of rule = visible
                'is_suppressed' => $isSuppressed,
            ];
        }

        return $result;
    }

    /**
     * Suppress a field for a system category.
     *
     * @param int $fieldId Metadata field ID
     * @param int $systemCategoryId System category ID
     * @return bool Success
     */
    public function suppressForCategory(int $fieldId, int $systemCategoryId): bool
    {
        // Verify field is system-scoped
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->where('scope', 'system')
            ->first();

        if (!$field) {
            throw new \InvalidArgumentException("Field {$fieldId} is not a system field or does not exist.");
        }

        // Verify system category exists
        $category = DB::table('system_categories')
            ->where('id', $systemCategoryId)
            ->first();

        if (!$category) {
            throw new \InvalidArgumentException("System category {$systemCategoryId} does not exist.");
        }

        // Create or update suppression rule
        DB::table('metadata_field_category_visibility')->updateOrInsert(
            [
                'metadata_field_id' => $fieldId,
                'system_category_id' => $systemCategoryId,
            ],
            [
                'is_visible' => false, // Suppressed
                'updated_at' => now(),
            ]
        );

        return true;
    }

    /**
     * Unsuppress a field for a system category (make visible again).
     *
     * @param int $fieldId Metadata field ID
     * @param int $systemCategoryId System category ID
     * @return bool Success
     */
    public function unsuppressForCategory(int $fieldId, int $systemCategoryId): bool
    {
        // Remove suppression rule (absence = visible)
        DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('system_category_id', $systemCategoryId)
            ->delete();

        return true;
    }

    /**
     * Check if a field is suppressed for a system category.
     *
     * @param int $fieldId Metadata field ID
     * @param int $systemCategoryId System category ID
     * @return bool True if suppressed, false if visible
     */
    public function isSuppressedForCategory(int $fieldId, int $systemCategoryId): bool
    {
        $rule = DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('system_category_id', $systemCategoryId)
            ->where('is_visible', false)
            ->first();

        return $rule !== null;
    }

    /**
     * Get suppressed category IDs for a field.
     *
     * @param int $fieldId Metadata field ID
     * @return array Array of suppressed system_category_ids
     */
    public function getSuppressedCategoryIds(int $fieldId): array
    {
        return DB::table('metadata_field_category_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('is_visible', false)
            ->pluck('system_category_id')
            ->toArray();
    }

    /**
     * Resolve effective visibility for a field in a category context.
     *
     * This checks if a field should be visible based on category suppression rules.
     * Used by resolvers to filter fields.
     *
     * @param int $fieldId Metadata field ID
     * @param int|null $systemCategoryId System category ID (from category.system_category_id)
     * @return bool True if visible, false if suppressed
     */
    public function isVisibleForCategory(int $fieldId, ?int $systemCategoryId): bool
    {
        // If no category, field is visible (no suppression)
        if ($systemCategoryId === null) {
            return true;
        }

        // Check if suppressed
        return !$this->isSuppressedForCategory($fieldId, $systemCategoryId);
    }
}
