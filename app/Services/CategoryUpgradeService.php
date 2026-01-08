<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\Category;
use App\Models\SystemCategory;
use App\Services\ActivityRecorder;

/**
 * CategoryUpgradeService
 *
 * Handles previewing and applying system category upgrades to brand categories.
 * Ensures tenant customizations are preserved and only explicitly approved fields are updated.
 */
class CategoryUpgradeService
{
    /**
     * Preview what changes would be applied if upgrading a category.
     *
     * @param Category $category
     * @return array
     */
    public function previewUpgrade(Category $category): array
    {
        // Find the latest SystemCategory version for this category's template
        $latest = $this->getLatestSystemVersion($category);

        if (!$latest) {
            throw new \Exception('No system category template found for this category.');
        }

        // Get the original system version that was used to create this category
        $original = $this->getOriginalSystemVersion($category);

        // Compare current category values with latest system template
        $fields = [];
        $allowedFields = ['name', 'icon', 'is_private', 'is_hidden'];

        foreach ($allowedFields as $field) {
            $currentValue = $category->$field;
            $newValue = $latest->$field;
            $originalValue = $original ? $original->$field : null;

            // Check if field has changed in latest version
            $changed = $original && $originalValue !== $newValue;

            // Check if category has been customized (differs from original)
            $customized = $original && $currentValue !== $originalValue;

            if ($changed) {
                $fields[$field] = [
                    'current' => $currentValue,
                    'new' => $newValue,
                    'original' => $originalValue,
                    'changed' => $changed,
                    'customized' => $customized,
                ];
            }
        }

        return [
            'current_version' => $category->system_version ?? 1,
            'latest_version' => $latest->version,
            'change_summary' => $latest->change_summary,
            'fields' => $fields,
        ];
    }

    /**
     * Apply an upgrade to a category, updating only approved fields.
     *
     * @param Category $category
     * @param array $approvedFields Array of field names to update
     * @return Category
     */
    public function applyUpgrade(Category $category, array $approvedFields): Category
    {
        // Validate approved fields
        $allowedFields = ['name', 'icon', 'is_private', 'is_hidden'];
        $approvedFields = array_intersect($approvedFields, $allowedFields);

        // Allow upgrade even if no fields are approved - this will just update the version number
        // This is useful when a new version exists but no field values have changed

        // Find the latest SystemCategory version
        $latest = $this->getLatestSystemVersion($category);

        if (!$latest) {
            throw new \Exception('No system category template found for this category.');
        }

        // Get the original system version
        $original = $this->getOriginalSystemVersion($category);

        // Store old version for logging
        $oldVersion = $category->system_version ?? 1;

        // Prepare updates
        $updates = [];

        // Only update fields if any are approved
        if (!empty($approvedFields)) {
            foreach ($approvedFields as $field) {
                // Only update if:
                // 1. Field is unchanged from original system version (safe to update), OR
                // 2. Field is explicitly approved (user wants to override customization)
                $currentValue = $category->$field;
                $originalValue = $original ? $original->$field : null;
                $newValue = $latest->$field;

                // Check if field was customized
                $wasCustomized = $original && $currentValue !== $originalValue;

                // If customized and user approved, update it
                // If not customized, safe to update
                if (!$wasCustomized || in_array($field, $approvedFields)) {
                    $updates[$field] = $newValue;
                }
            }

            // Update category fields only if there are updates
            if (!empty($updates)) {
                $category->update($updates);
            }
        }

        // Update version tracking
        $category->update([
            'system_version' => $latest->version,
            'system_category_id' => $latest->id,
            'upgrade_available' => false,
        ]);

        // Log activity
        ActivityRecorder::record(
            tenant: $category->tenant_id,
            eventType: EventType::CATEGORY_SYSTEM_UPGRADED,
            subject: $category,
            actor: null, // Auto-detect from Auth
            brand: $category->brand_id,
            metadata: [
                'old_version' => $oldVersion,
                'new_version' => $latest->version,
                'fields_updated' => !empty($updates) ? array_keys($updates) : [],
                'change_summary' => $latest->change_summary,
            ]
        );

        return $category->fresh();
    }

    /**
     * Get the latest SystemCategory version for a category's template.
     *
     * @param Category $category
     * @return SystemCategory|null
     */
    protected function getLatestSystemVersion(Category $category): ?SystemCategory
    {
        // If category has system_category_id, find latest version of that template
        if ($category->system_category_id) {
            $original = SystemCategory::find($category->system_category_id);
            if ($original) {
                return $original->getLatestVersion();
            }
        }

        // Otherwise, try to find by slug/asset_type match
        return SystemCategory::where('slug', $category->slug)
            ->where('asset_type', $category->asset_type)
            ->orderBy('version', 'desc')
            ->first();
    }

    /**
     * Get the original SystemCategory version that was used to create this category.
     *
     * @param Category $category
     * @return SystemCategory|null
     */
    protected function getOriginalSystemVersion(Category $category): ?SystemCategory
    {
        // If system_category_id is set, use it
        if ($category->system_category_id) {
            return SystemCategory::find($category->system_category_id);
        }

        // Otherwise, try to find by slug/asset_type match
        // For legacy categories, we'll try to find the version 1 or closest match
        return SystemCategory::where('slug', $category->slug)
            ->where('asset_type', $category->asset_type)
            ->orderBy('version', 'asc')
            ->first();
    }

    /**
     * Accept deletion of a category that has deletion_available = true.
     * Soft-deletes the category after user confirmation.
     *
     * @param Category $category
     * @return Category
     */
    public function acceptDeletion(Category $category): Category
    {
        if (!$category->deletion_available) {
            throw new \Exception('This category is not marked for deletion.');
        }

        // Store category info for logging before deletion
        $categoryName = $category->name;
        $categorySlug = $category->slug;
        $tenantId = $category->tenant_id;
        $brandId = $category->brand_id;

        // Soft delete the category
        $category->delete();

        // Log activity
        ActivityRecorder::record(
            tenant: $tenantId,
            eventType: EventType::CATEGORY_DELETED,
            subject: null, // Category is deleted, can't reference it
            actor: null, // Auto-detect from Auth
            brand: $brandId,
            metadata: [
                'category_name' => $categoryName,
                'category_slug' => $categorySlug,
                'deletion_type' => 'system_template_deleted',
            ]
        );

        return $category;
    }
}
