/**
 * Filter Query Ownership Map
 * 
 * Single-source-of-truth for URL query parameter ownership and persistence behavior.
 * 
 * This module defines which query parameters belong to which scope (global, category, UI)
 * and how they should be handled during category changes, navigation, and state management.
 * 
 * ⚠️ CONSTRAINTS:
 * - JS-only (no backend changes)
 * - No router changes
 * - No UI rendering
 * - Declarative only (no side effects)
 * - No runtime mutation
 * 
 * DESIGN PRINCIPLES:
 * 
 * 1. Global Query Params (owner: 'global', persistence: 'persist'):
 *    - Persist across category changes
 *    - Retained when switching contexts
 *    - Examples: search, tags, date ranges
 *    - Rationale: User expects global filters to remain active when browsing categories
 * 
 * 2. Category-Scoped Query Params (owner: 'category', persistence: 'purge_on_category_change'):
 *    - Purged when category changes
 *    - Reset to default when switching categories
 *    - Examples: metadata fields, orientation, dimensions, custom category fields
 *    - Rationale: Category-specific filters are invalid in different category contexts
 *                 Must be purged to prevent invalid filter state
 * 
 * 3. UI-Only State (owner: 'ui', persistence: 'never'):
 *    - Never persisted in URL
 *    - Stored in component state or localStorage only
 *    - Examples: expandedFilters, panelOpenState
 *    - Rationale: UI state should not pollute URLs or break deep linking
 *                 UI preferences are user-specific, not shareable
 * 
 * @module filterQueryOwnership
 */

/**
 * Query parameter ownership definition
 * 
 * @typedef {Object} QueryParamOwnership
 * @property {'global' | 'category' | 'ui'} owner - Parameter owner scope
 * @property {'persist' | 'purge_on_category_change' | 'never'} persistence - Persistence behavior
 * @property {'retain' | 'clear'} reset_behavior - Behavior when resetting filters
 * @property {string} notes - Required explanation of why this parameter has this configuration
 */

/**
 * Filter Query Ownership Map
 * 
 * Single-source-of-truth map defining ownership and persistence for all filter-related query parameters.
 * 
 * This map must be consulted whenever:
 * - Building URLs with filter state
 * - Parsing URLs to extract filter state
 * - Handling category changes
 * - Resetting filters
 * - Persisting/restoring filter state
 * 
 * @type {Object<string, QueryParamOwnership>}
 */
export const filterQueryOwnership = {
    // ============================================================================
    // GLOBAL FILTERS (persist across category changes)
    // ============================================================================
    
    /**
     * Search query parameter
     * 
     * Global text search across all assets regardless of category.
     * Persists when switching categories because search is context-independent.
     */
    search: {
        owner: 'global',
        persistence: 'persist',
        reset_behavior: 'clear',
        notes: 'Global text search persists across category changes. User expects search to remain active when browsing categories.',
    },
    
    /**
     * Tags filter parameter
     * 
     * Tag-based filtering that applies globally across all categories.
     * Tags are not category-specific, so they persist across category switches.
     */
    tags: {
        owner: 'global',
        persistence: 'persist',
        reset_behavior: 'clear',
        notes: 'Tags are global metadata that apply across all categories. Persisting tags maintains user filtering intent when switching categories.',
    },
    
    /**
     * Date range start parameter
     * 
     * Start date for date range filtering.
     * Date filters are global and persist across category changes.
     */
    date_from: {
        owner: 'global',
        persistence: 'persist',
        reset_behavior: 'clear',
        notes: 'Date range filters are global and context-independent. User expects date filters to persist when switching categories.',
    },
    
    /**
     * Date range end parameter
     * 
     * End date for date range filtering.
     * Date filters are global and persist across category changes.
     */
    date_to: {
        owner: 'global',
        persistence: 'persist',
        reset_behavior: 'clear',
        notes: 'Date range filters are global and context-independent. User expects date filters to persist when switching categories.',
    },
    
    /**
     * Lifecycle filter parameter
     * 
     * Lifecycle filter for asset status (e.g., pending_publication, unpublished, archived).
     * Global filter that persists across category changes as it represents asset state, not category-specific metadata.
     */
    lifecycle: {
        owner: 'global',
        persistence: 'persist',
        reset_behavior: 'clear',
        notes: 'Lifecycle filters represent asset state (pending, unpublished, archived) which is global and not category-specific. User expects lifecycle filters to persist when switching categories.',
    },
    
    // ============================================================================
    // CATEGORY-SCOPED FILTERS (purged on category change)
    // ============================================================================
    
    /**
     * Orientation filter parameter
     * 
     * Image orientation filter (landscape, portrait, square).
     * Category-scoped because orientation metadata may be category-specific.
     * Must be purged when switching categories to prevent invalid filter state.
     */
    orientation: {
        owner: 'category',
        persistence: 'purge_on_category_change',
        reset_behavior: 'clear',
        notes: 'Orientation is category-scoped metadata. Different categories may have different orientation schemas. Must be purged on category change to prevent invalid filter state.',
    },
    
    /**
     * Dimensions filter parameter
     * 
     * Image dimensions filter (width x height).
     * Category-scoped because dimension requirements vary by category.
     * Must be purged when switching categories.
     */
    dimensions: {
        owner: 'category',
        persistence: 'purge_on_category_change',
        reset_behavior: 'clear',
        notes: 'Dimensions are category-scoped. Different categories may have different dimension requirements. Must be purged on category change to prevent invalid filter state.',
    },
    
    // ============================================================================
    // METADATA FIELD FILTERS (category-scoped, dynamic)
    // ============================================================================
    
    /**
     * Metadata field filters (dynamic, by field_key)
     * 
     * These are category-specific metadata fields that vary by category.
     * Each metadata field has its own query parameter key (field_key).
     * 
     * NOTE: This is a template entry. Actual metadata fields are determined
     * dynamically from filterable_schema. All metadata fields follow this pattern.
     * 
     * Examples:
     * - color (if category has color metadata)
     * - photographer (if category has photographer metadata)
     * - campaign (if category has campaign metadata)
     */
    metadata_field: {
        owner: 'category',
        persistence: 'purge_on_category_change',
        reset_behavior: 'clear',
        notes: 'Metadata fields are category-specific. Each category has different metadata schemas. Filters for metadata fields that don\'t exist in the new category must be purged to prevent invalid filter state. This is a template - actual metadata fields use their field_key as the query param name.',
    },
    
    // ============================================================================
    // UI-ONLY STATE (never persisted in URL)
    // ============================================================================
    
    /**
     * Expanded filters state
     * 
     * Tracks which filter sections are expanded/collapsed in the UI.
     * This is UI state, not filter state, and should never appear in URLs.
     */
    expandedFilters: {
        owner: 'ui',
        persistence: 'never',
        reset_behavior: 'retain',
        notes: 'UI-only state for expanded/collapsed filter sections. Must never appear in URL to prevent URL pollution and broken deep linking. Stored in component state or localStorage only.',
    },
    
    /**
     * Filter panel open state
     * 
     * Tracks whether the filter panel is open or closed.
     * This is UI state, not filter state, and should never appear in URLs.
     */
    panelOpenState: {
        owner: 'ui',
        persistence: 'never',
        reset_behavior: 'retain',
        notes: 'UI-only state for filter panel visibility. Must never appear in URL to prevent URL pollution and broken deep linking. Stored in component state or localStorage only.',
    },
};

/**
 * Check if a query parameter is a global parameter
 * 
 * Global parameters persist across category changes and are retained
 * when switching contexts.
 * 
 * @param {string} param - Query parameter name
 * @returns {boolean} True if parameter is global
 * 
 * @example
 * ```javascript
 * isGlobalQueryParam('search') // Returns: true
 * isGlobalQueryParam('orientation') // Returns: false
 * isGlobalQueryParam('expandedFilters') // Returns: false
 * ```
 */
export function isGlobalQueryParam(param) {
    if (!param || typeof param !== 'string') {
        return false;
    }
    
    const ownership = filterQueryOwnership[param];
    if (!ownership) {
        // Unknown parameters default to category-scoped for safety
        return false;
    }
    
    return ownership.owner === 'global';
}

/**
 * Check if a query parameter should be persisted in URL
 * 
 * Parameters with persistence: 'persist' are included in URLs.
 * Parameters with persistence: 'purge_on_category_change' are included
 * but purged on category change.
 * Parameters with persistence: 'never' are never included in URLs.
 * 
 * @param {string} param - Query parameter name
 * @returns {boolean} True if parameter should be persisted in URL
 * 
 * @example
 * ```javascript
 * shouldPersistParam('search') // Returns: true
 * shouldPersistParam('orientation') // Returns: true (but purged on category change)
 * shouldPersistParam('expandedFilters') // Returns: false
 * ```
 */
export function shouldPersistParam(param) {
    if (!param || typeof param !== 'string') {
        return false;
    }
    
    const ownership = filterQueryOwnership[param];
    if (!ownership) {
        // Unknown parameters default to category-scoped (persist but purge on change)
        return true;
    }
    
    return ownership.persistence !== 'never';
}

/**
 * Check if a query parameter should be purged on category change
 * 
 * Category-scoped parameters are purged when switching categories
 * to prevent invalid filter state.
 * 
 * @param {string} param - Query parameter name
 * @returns {boolean} True if parameter should be purged on category change
 * 
 * @example
 * ```javascript
 * shouldPurgeOnCategoryChange('search') // Returns: false
 * shouldPurgeOnCategoryChange('orientation') // Returns: true
 * shouldPurgeOnCategoryChange('expandedFilters') // Returns: false (never persisted)
 * ```
 */
export function shouldPurgeOnCategoryChange(param) {
    if (!param || typeof param !== 'string') {
        return false;
    }
    
    const ownership = filterQueryOwnership[param];
    if (!ownership) {
        // Unknown parameters default to category-scoped (purge on change for safety)
        return true;
    }
    
    return ownership.persistence === 'purge_on_category_change';
}

/**
 * Get ownership information for a query parameter
 * 
 * Returns the full ownership definition for a parameter, or null if not found.
 * 
 * @param {string} param - Query parameter name
 * @returns {QueryParamOwnership|null} Ownership definition or null
 * 
 * @example
 * ```javascript
 * getQueryParamOwnership('search')
 * // Returns: { owner: 'global', persistence: 'persist', reset_behavior: 'clear', notes: '...' }
 * 
 * getQueryParamOwnership('unknown') // Returns: null
 * ```
 */
export function getQueryParamOwnership(param) {
    if (!param || typeof param !== 'string') {
        return null;
    }
    
    return filterQueryOwnership[param] || null;
}

/**
 * Get all query parameters for a specific owner
 * 
 * Returns an array of parameter names that belong to the specified owner.
 * 
 * @param {'global' | 'category' | 'ui'} owner - Owner type to filter by
 * @returns {Array<string>} Array of parameter names
 * 
 * @example
 * ```javascript
 * getParamsByOwner('global')
 * // Returns: ['search', 'tags', 'date_from', 'date_to']
 * 
 * getParamsByOwner('ui')
 * // Returns: ['expandedFilters', 'panelOpenState']
 * ```
 */
export function getParamsByOwner(owner) {
    if (!owner || typeof owner !== 'string') {
        return [];
    }
    
    return Object.keys(filterQueryOwnership).filter(
        param => filterQueryOwnership[param].owner === owner
    );
}

/**
 * Check if a parameter is a metadata field filter
 * 
 * Metadata fields are dynamic and determined from filterable_schema.
 * This helper checks if a parameter name matches a metadata field key.
 * 
 * NOTE: This is a heuristic check. The actual determination should be
 * made by checking if the parameter exists in filterable_schema.
 * 
 * @param {string} param - Query parameter name
 * @param {Array} filterable_schema - Array of filterable field definitions
 * @returns {boolean} True if parameter is a metadata field filter
 * 
 * @example
 * ```javascript
 * const schema = [{ field_key: 'color' }, { field_key: 'photographer' }]
 * isMetadataFieldParam('color', schema) // Returns: true
 * isMetadataFieldParam('search', schema) // Returns: false
 * ```
 */
export function isMetadataFieldParam(param, filterable_schema = []) {
    if (!param || typeof param !== 'string') {
        return false;
    }
    
    if (!Array.isArray(filterable_schema)) {
        return false;
    }
    
    // Check if param matches any field_key in filterable_schema
    return filterable_schema.some(
        field => field.field_key === param || field.key === param
    );
}

/**
 * Get all global query parameters
 * 
 * Convenience function to get all parameters that persist across category changes.
 * 
 * @returns {Array<string>} Array of global parameter names
 */
export function getGlobalParams() {
    return getParamsByOwner('global');
}

/**
 * Get all category-scoped query parameters
 * 
 * Convenience function to get all parameters that are purged on category change.
 * 
 * @returns {Array<string>} Array of category-scoped parameter names
 */
export function getCategoryScopedParams() {
    return getParamsByOwner('category');
}

/**
 * Get all UI-only parameters
 * 
 * Convenience function to get all parameters that should never appear in URLs.
 * 
 * @returns {Array<string>} Array of UI-only parameter names
 */
export function getUIOnlyParams() {
    return getParamsByOwner('ui');
}
