/**
 * Filter Visibility Rules
 * 
 * Single-source-of-truth module defining filter visibility semantics.
 * 
 * This module provides deterministic helpers for determining filter visibility
 * based on scope compatibility, available values, and filter tier.
 * 
 * ⚠️ CONSTRAINTS:
 * - JS-only (no backend changes)
 * - No UI rendering
 * - No resolver changes
 * - Pure deterministic helpers
 * - "Disabled" state is explicitly forbidden
 * 
 * DESIGN PRINCIPLES:
 * 
 * 1. Visibility States:
 *    - 'visible': Filter is shown in UI and available for use
 *    - 'hidden': Filter is not shown in UI (but may exist in data)
 *    - 'disabled': FORBIDDEN - Filters must never be in a disabled state
 * 
 * 2. Why "Disabled" is Forbidden:
 *    - Disabled filters create UX confusion (why can't I use this?)
 *    - Disabled filters waste screen space (grayed-out, non-functional)
 *    - Hidden filters improve signal density (only show what's usable)
 *    - If a filter can't be used, it shouldn't be shown at all
 *    - Better to hide than disable - user doesn't need to see unusable filters
 * 
 * 3. Why Empty Dropdowns are UX-Hostile:
 *    - Empty dropdowns frustrate users (click → nothing happens)
 *    - Empty dropdowns waste clicks and cognitive load
 *    - Empty dropdowns suggest broken functionality
 *    - Better to hide filters with no values than show empty controls
 *    - Hidden filters with no values improve signal density
 * 
 * 4. Why Hidden Filters Improve Signal Density:
 *    - Only show filters that are actually usable
 *    - Reduces visual clutter and cognitive load
 *    - Users see only relevant filtering options
 *    - Enables accurate "X filters hidden" messaging
 *    - Better UX than showing disabled/empty filters
 * 
 * 5. Visibility Determination:
 *    - Scope compatibility (via filterScopeRules) → must pass
 *    - Available values → must exist (unless primary filter)
 *    - Primary filters → always visible (even if no values)
 *    - Secondary filters → hidden if no values
 * 
 * @module filterVisibilityRules
 */

import { isFilterCompatible } from './filterScopeRules';

/**
 * Filter visibility state
 * 
 * @typedef {'visible' | 'hidden'} FilterVisibilityState
 */

/**
 * Visibility context
 * 
 * @typedef {Object} VisibilityContext
 * @property {number|null} category_id - Current category ID (null = "All Categories")
 * @property {string} asset_type - Current asset type (e.g., 'asset', 'deliverable')
 * @property {Object<string, Array>} available_values - Map of field_key to available values
 */

/**
 * Check if a filter has available values
 * 
 * A filter has available values if:
 * - available_values[filter.key] exists and is an array with length ≥ 1
 * - OR the filter is a primary filter (primary filters are always considered to have values)
 * 
 * Primary filters are always considered to have available values because:
 * - They are always visible (Search, Category, Asset Type, Brand)
 * - They don't depend on metadata values
 * - They are system-level filters, not data-driven
 * 
 * @param {Object} filter - Filter descriptor (must have 'key' property)
 * @param {Object<string, Array>} available_values - Map of field_key to available values
 * @returns {boolean} True if filter has available values
 * 
 * @example
 * ```javascript
 * const available_values = {
 *   'color': ['red', 'blue', 'green'],
 *   'orientation': ['landscape', 'portrait'],
 *   'empty_field': [],
 * }
 * 
 * hasAvailableValues({ key: 'color' }, available_values)
 * // Returns: true
 * 
 * hasAvailableValues({ key: 'empty_field' }, available_values)
 * // Returns: false (empty array)
 * 
 * hasAvailableValues({ key: 'missing_field' }, available_values)
 * // Returns: false (not in available_values)
 * 
 * // Primary filters always return true
 * hasAvailableValues({ key: 'search', is_primary: true }, {})
 * // Returns: true (primary filters always have values)
 * ```
 */
export function hasAvailableValues(filter, available_values = {}) {
    if (!filter || typeof filter !== 'object') {
        return false;
    }
    
    const filterKey = filter.key || filter.field_key;
    if (!filterKey || typeof filterKey !== 'string') {
        return false;
    }
    
    // System primary filters always have available values
    // They are system-level filters (Search, Category, Asset Type, Brand)
    // and don't depend on metadata values
    // Metadata primary filters (is_primary === true but not system filters) still need to check available_values
    const isSystemPrimary = filter.is_primary === true && (
        filter.key === 'search' || 
        filter.key === 'category' || 
        filter.key === 'asset_type' || 
        filter.key === 'brand' ||
        filter.field_key === 'search' ||
        filter.field_key === 'category' ||
        filter.field_key === 'asset_type' ||
        filter.field_key === 'brand'
    );
    
    if (isSystemPrimary) {
        return true; // System primary filters always have values
    }
    
    // Metadata primary filters need to check available_values
    
    // Check if filter key exists in available_values
    const values = available_values[filterKey];
    
    // If not in available_values, assume no values
    if (values === undefined) {
        return false;
    }
    
    // If values is an array, check if it has items
    if (Array.isArray(values)) {
        return values.length > 0;
    }
    
    // If values is a number (count), check if > 0
    if (typeof values === 'number') {
        return values > 0;
    }
    
    // Default to false for other types
    return false;
}

/**
 * Get visibility state for a single filter
 * 
 * Visibility determination rules (in order):
 * 1. If filter is incompatible via filterScopeRules → 'hidden'
 * 2. If filter is primary → 'visible' (primary filters are always visible)
 * 3. If filter has no available values → 'hidden'
 * 4. Else → 'visible'
 * 
 * Note: "Disabled" is NOT a valid state. Filters are either visible or hidden.
 * Disabled filters create UX confusion and waste screen space. If a filter
 * can't be used, it should be hidden, not disabled.
 * 
 * @param {Object} filter - Filter descriptor
 * @param {VisibilityContext} context - Visibility context
 * @returns {FilterVisibilityState} 'visible' or 'hidden'
 * 
 * @example
 * ```javascript
 * const context = {
 *   category_id: 1,
 *   asset_type: 'asset',
 *   available_values: {
 *     'color': ['red', 'blue'],
 *     'orientation': [],
 *   }
 * }
 * 
 * // Compatible filter with values → visible
 * getFilterVisibilityState(
 *   { key: 'color', is_global: false, category_ids: [1], asset_types: ['asset'] },
 *   context
 * )
 * // Returns: 'visible'
 * 
 * // Compatible filter without values → hidden
 * getFilterVisibilityState(
 *   { key: 'orientation', is_global: false, category_ids: [1], asset_types: ['asset'] },
 *   context
 * )
 * // Returns: 'hidden'
 * 
 * // Incompatible filter → hidden
 * getFilterVisibilityState(
 *   { key: 'color', is_global: false, category_ids: [2], asset_types: ['asset'] },
 *   context
 * )
 * // Returns: 'hidden'
 * 
 * // Primary filter → always visible
 * getFilterVisibilityState(
 *   { key: 'search', is_primary: true, is_global: true },
 *   context
 * )
 * // Returns: 'visible'
 * ```
 */
export function getFilterVisibilityState(filter, context) {
    if (!filter || typeof filter !== 'object') {
        return 'hidden';
    }
    
    if (!context || typeof context !== 'object') {
        return 'hidden';
    }
    
    const { category_id, asset_type, available_values = {} } = context;
    const fieldKey = filter.key || filter.field_key;
    
    // DEBUG: Log visibility check
    console.log('[filterVisibilityRules] DEBUG - getFilterVisibilityState:', {
        field_key: fieldKey,
        is_primary: filter.is_primary,
        category_id,
        asset_type,
        available_values_for_field: available_values[fieldKey],
    });
    
    // Rule 1: Check scope compatibility
    // If filter is incompatible with current category/asset_type, it's hidden
    const isCompatible = isFilterCompatible(filter, { category_id, asset_type });
    console.log('[filterVisibilityRules] DEBUG - isFilterCompatible:', isCompatible, 'for', fieldKey);
    if (!isCompatible) {
        console.log('[filterVisibilityRules] DEBUG - HIDDEN (scope incompatible):', fieldKey);
        return 'hidden';
    }
    
    // Rule 2: Primary filters visibility
    // System primary filters (Search, Category, Asset Type, Brand) are always visible
    // Metadata primary filters (is_primary === true) still require available_values
    // This prevents showing empty dropdowns for metadata filters (UX-hostile)
    const isSystemPrimary = filter.is_primary === true && (
        filter.key === 'search' || 
        filter.key === 'category' || 
        filter.key === 'asset_type' || 
        filter.key === 'brand' ||
        filter.field_key === 'search' ||
        filter.field_key === 'category' ||
        filter.field_key === 'asset_type' ||
        filter.field_key === 'brand'
    );
    
    console.log('[filterVisibilityRules] DEBUG - isSystemPrimary:', isSystemPrimary, 'for', fieldKey);
    
    if (isSystemPrimary) {
        console.log('[filterVisibilityRules] DEBUG - VISIBLE (system primary):', fieldKey);
        return 'visible'; // System primary filters are always visible
    }
    
    // Metadata primary filters (is_primary === true but not system filters)
    // Still need to check available_values to prevent empty dropdowns
    
    // Rule 3: Check available values
    // If filter has no available values, it's hidden
    // This prevents showing empty dropdowns (UX-hostile)
    const hasValues = hasAvailableValues(filter, available_values);
    console.log('[filterVisibilityRules] DEBUG - hasAvailableValues:', hasValues, 'for', fieldKey);
    if (!hasValues) {
        console.log('[filterVisibilityRules] DEBUG - HIDDEN (no available values):', fieldKey);
        return 'hidden';
    }
    
    // Rule 4: All checks passed → visible
    console.log('[filterVisibilityRules] DEBUG - VISIBLE (all checks passed):', fieldKey);
    return 'visible';
}

/**
 * Get only visible filters from an array
 * 
 * Filters an array of filters to only those with visibility === 'visible'.
 * Useful for rendering only usable filters in the UI.
 * 
 * @param {Array<Object>} filters - Array of filter descriptors
 * @param {VisibilityContext} context - Visibility context
 * @returns {Array<Object>} Array of visible filters only
 * 
 * @example
 * ```javascript
 * const filters = [
 *   { key: 'search', is_primary: true, is_global: true },
 *   { key: 'color', is_global: false, category_ids: [1], asset_types: ['asset'] },
 *   { key: 'orientation', is_global: false, category_ids: [1], asset_types: ['asset'] },
 * ]
 * 
 * const context = {
 *   category_id: 1,
 *   asset_type: 'asset',
 *   available_values: {
 *     'color': ['red', 'blue'],
 *     'orientation': [], // No values
 *   }
 * }
 * 
 * getVisibleFilters(filters, context)
 * // Returns: [
 * //   { key: 'search', is_primary: true, is_global: true },
 * //   { key: 'color', is_global: false, category_ids: [1], asset_types: ['asset'] }
 * // ]
 * // Note: 'orientation' is hidden because it has no available values
 * ```
 */
export function getVisibleFilters(filters, context) {
    if (!Array.isArray(filters)) {
        return [];
    }
    
    if (!context || typeof context !== 'object') {
        return [];
    }
    
    return filters.filter(filter => {
        const visibility = getFilterVisibilityState(filter, context);
        return visibility === 'visible';
    });
}

/**
 * Get only hidden filters from an array
 * 
 * Filters an array of filters to only those with visibility === 'hidden'.
 * Useful for:
 * - Showing "X filters hidden" messaging
 * - Debugging visibility issues
 * - Understanding why filters are not shown
 * 
 * @param {Array<Object>} filters - Array of filter descriptors
 * @param {VisibilityContext} context - Visibility context
 * @returns {Array<Object>} Array of hidden filters only
 * 
 * @example
 * ```javascript
 * const filters = [
 *   { key: 'search', is_primary: true, is_global: true },
 *   { key: 'color', is_global: false, category_ids: [1], asset_types: ['asset'] },
 *   { key: 'orientation', is_global: false, category_ids: [1], asset_types: ['asset'] },
 * ]
 * 
 * const context = {
 *   category_id: 1,
 *   asset_type: 'asset',
 *   available_values: {
 *     'color': ['red', 'blue'],
 *     'orientation': [], // No values
 *   }
 * }
 * 
 * getHiddenFilters(filters, context)
 * // Returns: [
 * //   { key: 'orientation', is_global: false, category_ids: [1], asset_types: ['asset'] }
 * // ]
 * // Note: 'orientation' is hidden because it has no available values
 * ```
 */
export function getHiddenFilters(filters, context) {
    if (!Array.isArray(filters)) {
        return [];
    }
    
    if (!context || typeof context !== 'object') {
        return filters; // If no context, all filters are considered hidden
    }
    
    return filters.filter(filter => {
        const visibility = getFilterVisibilityState(filter, context);
        return visibility === 'hidden';
    });
}

/**
 * Get count of visible filters
 * 
 * Convenience helper to get the count of visible filters.
 * Useful for UI messaging like "Showing 5 of 8 filters".
 * 
 * @param {Array<Object>} filters - Array of filter descriptors
 * @param {VisibilityContext} context - Visibility context
 * @returns {number} Count of visible filters
 * 
 * @example
 * ```javascript
 * const filters = [/* ... filters ... *\/]
 * const context = { category_id: 1, asset_type: 'asset', available_values: {} }
 * 
 * getVisibleFilterCount(filters, context)
 * // Returns: 5
 * ```
 */
export function getVisibleFilterCount(filters, context) {
    return getVisibleFilters(filters, context).length;
}

/**
 * Get count of hidden filters
 * 
 * Convenience helper to get the count of hidden filters.
 * Useful for UI messaging like "5 filters hidden" or "Showing 3 of 8 filters (5 hidden)".
 * 
 * @param {Array<Object>} filters - Array of filter descriptors
 * @param {VisibilityContext} context - Visibility context
 * @returns {number} Count of hidden filters
 * 
 * @example
 * ```javascript
 * const filters = [/* ... filters ... *\/]
 * const context = { category_id: 1, asset_type: 'asset', available_values: {} }
 * 
 * getHiddenFilterCount(filters, context)
 * // Returns: 3
 * ```
 */
export function getHiddenFilterCount(filters, context) {
    return getHiddenFilters(filters, context).length;
}

/**
 * Check if a filter is visible
 * 
 * Convenience helper to check if a single filter is visible.
 * 
 * @param {Object} filter - Filter descriptor
 * @param {VisibilityContext} context - Visibility context
 * @returns {boolean} True if filter is visible
 * 
 * @example
 * ```javascript
 * const filter = { key: 'color', is_global: false, category_ids: [1] }
 * const context = { category_id: 1, asset_type: 'asset', available_values: { color: ['red'] } }
 * 
 * isFilterVisible(filter, context)
 * // Returns: true
 * ```
 */
export function isFilterVisible(filter, context) {
    return getFilterVisibilityState(filter, context) === 'visible';
}

/**
 * Check if a filter is hidden
 * 
 * Convenience helper to check if a single filter is hidden.
 * 
 * @param {Object} filter - Filter descriptor
 * @param {VisibilityContext} context - Visibility context
 * @returns {boolean} True if filter is hidden
 * 
 * @example
 * ```javascript
 * const filter = { key: 'orientation', is_global: false, category_ids: [1] }
 * const context = { category_id: 1, asset_type: 'asset', available_values: { orientation: [] } }
 * 
 * isFilterHidden(filter, context)
 * // Returns: true
 * ```
 */
export function isFilterHidden(filter, context) {
    return getFilterVisibilityState(filter, context) === 'hidden';
}
