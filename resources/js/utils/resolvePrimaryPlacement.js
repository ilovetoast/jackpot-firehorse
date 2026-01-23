/**
 * Resolve Primary Filter Placement Helper
 * 
 * ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
 * A field may be primary in Photography but secondary in Logos.
 * 
 * This helper function resolves the effective primary placement for a field in a specific category.
 * 
 * Resolution order:
 * 1. Category override (metadata_field_visibility.is_primary where category_id is set) - highest priority
 * 2. Global is_primary (metadata_fields.is_primary) - deprecated, backward compatibility only
 * 3. Default to false (secondary)
 * 
 * @param {Object} field - Field object from schema (already contains effective_is_primary from MetadataSchemaResolver)
 * @param {number|null} categoryId - Category ID to check placement for
 * @param {Object|null} categoryData - Category-specific override data (from API)
 * @returns {boolean} true if primary for this category, false if secondary
 * 
 * @example
 * ```javascript
 * // Field is primary in Photography (category 3) but secondary in Logos (category 2)
 * resolvePrimaryPlacement(field, 3, { overrides: { 3: { is_primary: true } } })
 * // Returns: true
 * 
 * resolvePrimaryPlacement(field, 2, { overrides: { 3: { is_primary: true } } })
 * // Returns: false (no override for category 2, falls back to global is_primary or false)
 * ```
 */
export function resolvePrimaryPlacement(field, categoryId, categoryData = null) {
    if (!categoryId || !categoryData) {
        // No category context - fallback to global is_primary (backward compatibility)
        // TODO: Deprecate metadata_fields.is_primary - migrate to category overrides
        return field.is_primary ?? false
    }
    
    // Check category-specific override (highest priority)
    const categoryOverride = categoryData.overrides?.[categoryId]
    if (categoryOverride && categoryOverride.is_primary !== null && categoryOverride.is_primary !== undefined) {
        return categoryOverride.is_primary // Category override wins
    }
    
    // Fallback to global is_primary (deprecated)
    // TODO: Remove this fallback once all fields are migrated to category overrides
    return field.is_primary ?? false
}
