/**
 * Category Utility Functions
 * 
 * Provides reusable functions for filtering and working with categories
 * across the frontend application.
 */

/**
 * Filter categories to only include active, selectable categories.
 * 
 * This function mimics the backend Category::scopeActive() and Category::isActive()
 * logic to ensure consistency between backend and frontend filtering.
 * 
 * Filters out:
 * - Templates (categories with no ID or ID === 0)
 * - Deleted system categories (where template_exists === false)
 * - Soft-deleted categories (where deleted_at is set)
 * 
 * @param {Array} categories - Array of category objects
 * @returns {Array} Filtered array of active categories
 */
export function filterActiveCategories(categories) {
    if (!categories || !Array.isArray(categories)) {
        return [];
    }

    return categories.filter(category => {
        // Filter out templates (no ID) - same as backend scopeActive()
        if (category.id == null || category.id === undefined || category.id === 0) {
            return false;
        }

        // Filter out soft-deleted categories (deleted_at should be null/undefined)
        if (category.deleted_at) {
            return false;
        }

        // Filter out deleted system categories (template no longer exists)
        // This matches the backend Category::isActive() logic
        if (category.is_system && category.template_exists === false) {
            return false;
        }

        return true;
    });
}

/**
 * Check if a single category is active and selectable.
 * 
 * @param {Object} category - Category object
 * @returns {boolean} True if category is active and selectable
 */
export function isCategoryActive(category) {
    if (!category) {
        return false;
    }

    // Must have an ID (not a template)
    if (category.id == null || category.id === undefined || category.id === 0) {
        return false;
    }

    // Must not be soft-deleted
    if (category.deleted_at) {
        return false;
    }

    // System categories must have an existing template
    if (category.is_system && category.template_exists === false) {
        return false;
    }

    return true;
}
