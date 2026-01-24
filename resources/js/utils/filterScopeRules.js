/**
 * Filter Scope Compatibility Rules
 * 
 * Single-source-of-truth module defining filter scope compatibility rules.
 * 
 * This module provides deterministic helpers for determining filter compatibility
 * based on global scope, category scope, and asset type scope.
 * 
 * ⚠️ CONSTRAINTS:
 * - JS-only (no backend changes)
 * - No UI rendering
 * - No resolver changes
 * - Deterministic helpers only
 * - Pure functions with no side effects
 * 
 * DESIGN PRINCIPLES:
 * 
 * 1. Global Filters (is_global === true):
 *    - Available across all categories and asset types
 *    - Persist when switching categories (user expectation)
 *    - Example: "Search", "Date Created", "Uploaded By"
 * 
 * 2. Category Scoping (category_ids):
 *    - null: Filter applies to all categories (global scope)
 *    - Array: Filter applies only to listed category IDs
 *    - Empty array []: Filter applies to no categories (effectively disabled)
 * 
 * 3. Asset Type Scoping (asset_types):
 *    - null: Filter applies to all asset types (global scope)
 *    - Array: Filter applies only to listed asset types
 *    - Empty array []: Filter applies to no asset types (effectively disabled)
 * 
 * 4. "All Categories" Context (category_id === null):
 *    - Represents viewing assets across all categories
 *    - Only global filters (is_global === true) are compatible
 *    - Category-specific filters are incompatible (no category context)
 * 
 * 5. Disabled Filters:
 *    - Filters with empty category_ids or asset_types arrays are FORBIDDEN by design
 *    - These filters have no valid scope and must be purged
 *    - The system should never create or persist such filters
 * 
 * @module filterScopeRules
 */

/**
 * Filter descriptor (from FilterDescriptor contract)
 * 
 * @typedef {Object} FilterDescriptor
 * @property {boolean} is_global - Whether filter applies globally
 * @property {Array<number>|null} category_ids - Category IDs filter applies to (null = all)
 * @property {Array<string>|null} asset_types - Asset types filter applies to (null = all)
 */

/**
 * Compatibility context
 * 
 * @typedef {Object} CompatibilityContext
 * @property {number|null} category_id - Current category ID (null = "All Categories")
 * @property {string} asset_type - Current asset type (e.g., 'asset', 'deliverable')
 */

/**
 * Check if a filter is a global filter
 * 
 * Global filters are available across all categories and asset types.
 * They persist when switching categories, providing consistent filtering
 * regardless of category context.
 * 
 * @param {FilterDescriptor} filter - Filter descriptor to check
 * @returns {boolean} True if filter.is_global === true
 * 
 * @example
 * ```javascript
 * isGlobalFilter({ is_global: true, category_ids: null, asset_types: null })
 * // Returns: true
 * 
 * isGlobalFilter({ is_global: false, category_ids: [1, 2], asset_types: ['asset'] })
 * // Returns: false
 * ```
 */
export function isGlobalFilter(filter) {
    if (!filter || typeof filter !== 'object') {
        return false;
    }
    return filter.is_global === true;
}

/**
 * Check if a filter is compatible with a specific category
 * 
 * Compatibility rules:
 * - If category_id is null ("All Categories" context):
 *   → Only global filters (is_global === true) are compatible
 *   → Category-specific filters are incompatible (no category context)
 * 
 * - If category_id is a number:
 *   → If filter.category_ids is null → compatible (filter applies to all categories)
 *   → If filter.category_ids includes category_id → compatible
 *   → Else → incompatible
 * 
 * @param {FilterDescriptor} filter - Filter descriptor to check
 * @param {number|null} category_id - Category ID to check compatibility with (null = "All Categories")
 * @returns {boolean} True if filter is compatible with the category
 * 
 * @example
 * ```javascript
 * // Global filter - compatible with any category
 * isCategoryCompatible(
 *   { is_global: true, category_ids: null },
 *   5
 * )
 * // Returns: true
 * 
 * // Category-specific filter - compatible with listed category
 * isCategoryCompatible(
 *   { is_global: false, category_ids: [1, 2, 5] },
 *   5
 * )
 * // Returns: true
 * 
 * // Category-specific filter - incompatible with other category
 * isCategoryCompatible(
 *   { is_global: false, category_ids: [1, 2] },
 *   5
 * )
 * // Returns: false
 * 
 * // "All Categories" context - only global filters compatible
 * isCategoryCompatible(
 *   { is_global: true, category_ids: null },
 *   null
 * )
 * // Returns: true
 * 
 * isCategoryCompatible(
 *   { is_global: false, category_ids: [1, 2] },
 *   null
 * )
 * // Returns: false (category-specific filter incompatible with "All Categories")
 * ```
 */
export function isCategoryCompatible(filter, category_id) {
    if (!filter || typeof filter !== 'object') {
        return false;
    }
    
    // "All Categories" context (category_id === null)
    // Only global filters are compatible - category-specific filters have no context
    if (category_id === null) {
        return isGlobalFilter(filter);
    }
    
    // Specific category context (category_id is a number)
    
    // If filter.category_ids is null, undefined, or missing, filter applies to all categories
    // This is the case for metadata fields (they're category-scoped via visibility resolver, not explicit category_ids)
    // Metadata fields from filterable_schema have category_ids: null by design
    if (filter.category_ids === null || filter.category_ids === undefined || !('category_ids' in filter)) {
        return true; // Metadata fields with null/undefined/missing category_ids apply to all categories
    }
    
    // If filter.category_ids is not an array, incompatible
    if (!Array.isArray(filter.category_ids)) {
        return false;
    }
    
    // Empty array means filter applies to no categories (disabled - forbidden by design)
    // Return false to indicate incompatibility
    if (filter.category_ids.length === 0) {
        return false;
    }
    
    // Check if category_id is included in filter.category_ids
    return filter.category_ids.includes(category_id);
}

/**
 * Check if a filter is compatible with a specific asset type
 * 
 * Compatibility rules:
 * - If filter.asset_types is null → compatible (filter applies to all asset types)
 * - If filter.asset_types includes asset_type → compatible
 * - Else → incompatible
 * 
 * Note: Unlike category compatibility, asset type compatibility does not have
 * a special "all asset types" context. The asset_type parameter is always a
 * specific string value.
 * 
 * @param {FilterDescriptor} filter - Filter descriptor to check
 * @param {string} asset_type - Asset type to check compatibility with (e.g., 'asset', 'deliverable')
 * @returns {boolean} True if filter is compatible with the asset type
 * 
 * @example
 * ```javascript
 * // Global asset type scope - compatible with any asset type
 * isAssetTypeCompatible(
 *   { asset_types: null },
 *   'asset'
 * )
 * // Returns: true
 * 
 * // Specific asset type scope - compatible with listed type
 * isAssetTypeCompatible(
 *   { asset_types: ['asset', 'deliverable'] },
 *   'asset'
 * )
 * // Returns: true
 * 
 * // Specific asset type scope - incompatible with other type
 * isAssetTypeCompatible(
 *   { asset_types: ['asset'] },
 *   'deliverable'
 * )
 * // Returns: false
 * 
 * // Empty array means filter applies to no asset types (disabled - forbidden by design)
 * isAssetTypeCompatible(
 *   { asset_types: [] },
 *   'asset'
 * )
 * // Returns: false
 * ```
 */
export function isAssetTypeCompatible(filter, asset_type) {
    if (!filter || typeof filter !== 'object') {
        return false;
    }
    
    if (!asset_type || typeof asset_type !== 'string') {
        return false;
    }
    
    // If filter.asset_types is null, undefined, or missing, filter applies to all asset types
    // This is the case for metadata fields (they're asset_type-scoped via applies_to, not explicit asset_types)
    // Metadata fields from filterable_schema may have asset_types: null (for 'all') or ['image'] (for specific types)
    if (filter.asset_types === null || filter.asset_types === undefined || !('asset_types' in filter)) {
        return true; // Metadata fields with null/undefined/missing asset_types apply to all asset types
    }
    
    // If filter.asset_types is not an array, incompatible
    if (!Array.isArray(filter.asset_types)) {
        return false;
    }
    
    // Empty array means filter applies to no asset types (disabled - forbidden by design)
    // Return false to indicate incompatibility
    if (filter.asset_types.length === 0) {
        return false;
    }
    
    // Check if asset_type is included in filter.asset_types
    return filter.asset_types.includes(asset_type);
}

/**
 * Check if a filter is compatible with both category and asset type
 * 
 * A filter is compatible only if BOTH category and asset type compatibility pass.
 * This ensures filters are only shown when they are valid for the current context.
 * 
 * Incompatible filters must be purged to prevent:
 * - UI showing filters that don't apply to current context
 * - User confusion about why filters don't work
 * - Invalid filter state persisting across context switches
 * 
 * @param {FilterDescriptor} filter - Filter descriptor to check
 * @param {CompatibilityContext} context - Compatibility context with category_id and asset_type
 * @returns {boolean} True if filter is compatible with both category and asset type
 * 
 * @example
 * ```javascript
 * const filter = {
 *   is_global: false,
 *   category_ids: [1, 2],
 *   asset_types: ['asset']
 * }
 * 
 * // Compatible - matches both category and asset type
 * isFilterCompatible(filter, { category_id: 1, asset_type: 'asset' })
 * // Returns: true
 * 
 * // Incompatible - category doesn't match
 * isFilterCompatible(filter, { category_id: 5, asset_type: 'asset' })
 * // Returns: false
 * 
 * // Incompatible - asset type doesn't match
 * isFilterCompatible(filter, { category_id: 1, asset_type: 'deliverable' })
 * // Returns: false
 * 
 * // "All Categories" context - only global filters compatible
 * isFilterCompatible(
 *   { is_global: true, category_ids: null, asset_types: ['asset'] },
 *   { category_id: null, asset_type: 'asset' }
 * )
 * // Returns: true
 * 
 * isFilterCompatible(
 *   { is_global: false, category_ids: [1, 2], asset_types: ['asset'] },
 *   { category_id: null, asset_type: 'asset' }
 * )
 * // Returns: false (category-specific filter incompatible with "All Categories")
 * ```
 */
export function isFilterCompatible(filter, context) {
    if (!filter || typeof filter !== 'object') {
        console.log('[filterScopeRules] DEBUG - isFilterCompatible: filter is invalid', filter);
        return false;
    }
    
    if (!context || typeof context !== 'object') {
        console.log('[filterScopeRules] DEBUG - isFilterCompatible: context is invalid', context);
        return false;
    }
    
    const { category_id, asset_type } = context;
    
    const categoryCompatible = isCategoryCompatible(filter, category_id);
    const assetTypeCompatible = isAssetTypeCompatible(filter, asset_type);
    
    console.log('[filterScopeRules] DEBUG - isFilterCompatible:', {
        field_key: filter.field_key || filter.key,
        category_id,
        asset_type,
        filter_category_ids: filter.category_ids,
        filter_asset_types: filter.asset_types,
        categoryCompatible,
        assetTypeCompatible,
        result: categoryCompatible && assetTypeCompatible,
    });
    
    // Both category and asset type compatibility must pass
    return categoryCompatible && assetTypeCompatible;
}

/**
 * Filter an array of filters to only compatible ones
 * 
 * Convenience helper to filter out incompatible filters from an array.
 * Useful for purging incompatible filters before rendering or processing.
 * 
 * @param {Array<FilterDescriptor>} filters - Array of filter descriptors
 * @param {CompatibilityContext} context - Compatibility context
 * @returns {Array<FilterDescriptor>} Array of compatible filters only
 * 
 * @example
 * ```javascript
 * const filters = [
 *   { is_global: true, category_ids: null, asset_types: ['asset'] },
 *   { is_global: false, category_ids: [1], asset_types: ['asset'] },
 *   { is_global: false, category_ids: [2], asset_types: ['deliverable'] },
 * ]
 * 
 * filterCompatible(filters, { category_id: 1, asset_type: 'asset' })
 * // Returns: [
 * //   { is_global: true, category_ids: null, asset_types: ['asset'] },
 * //   { is_global: false, category_ids: [1], asset_types: ['asset'] }
 * // ]
 * ```
 */
export function filterCompatible(filters, context) {
    if (!Array.isArray(filters)) {
        return [];
    }
    
    return filters.filter(filter => isFilterCompatible(filter, context));
}
