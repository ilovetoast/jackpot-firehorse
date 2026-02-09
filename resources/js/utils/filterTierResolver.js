/**
 * Filter Tier Resolver
 * 
 * Classifies filters into tiers: primary | secondary | hidden
 * 
 * This is a JS-level configuration/resolver that determines filter visibility
 * based on brand + category + asset_type + available values.
 * 
 * ⚠️ PHASE CONSTRAINTS:
 * - Do NOT refactor existing filter logic
 * - Do NOT rename existing query params, enums, or models
 * - All changes must be additive and backwards compatible
 * - Asset filtering MUST remain scoped by: brand_id, category_id (nullable), asset_type
 * 
 * USAGE EXAMPLE:
 * ```javascript
 * import { resolveFilterTiers } from '../utils/filterTierResolver'
 * 
 * const config = {
 *   brand_id: 1,
 *   category_id: 5,
 *   asset_type: 'asset',
 *   filterable_schema: [...], // From backend MetadataFilterService
 *   is_multi_brand: brands.length > 1,
 *   available_values: {
 *     'color': ['red', 'blue', 'green'],
 *     'orientation': ['landscape', 'portrait'],
 *     'empty_field': [],
 *   }
 * }
 * 
 * const tiers = resolveFilterTiers(config)
 * // tiers.primary - Always visible filters
 * // tiers.secondary - Collapsed by default, metadata fields with values
 * // tiers.hidden - Filters with zero available values
 * ```
 * 
 * NOTE: Primary filters (Search, Category, Asset Type, Brand) are conceptual
 * and handled separately in the UI. This resolver classifies metadata fields
 * from filterable_schema. Metadata fields can be marked as primary via
 * is_primary flag for future extensibility.
 * 
 * @module filterTierResolver
 */

/**
 * Filter tier classification
 * @typedef {'primary' | 'secondary' | 'hidden'} FilterTier
 */

/**
 * Filter classification result
 * @typedef {Object} FilterClassification
 * @property {string} field_key - The field key identifier
 * @property {FilterTier} tier - The tier classification
 * @property {Object} field - The original field definition
 */

/**
 * Configuration for filter tier resolution
 * @typedef {Object} FilterTierConfig
 * @property {number} brand_id - Brand ID (required)
 * @property {number|null} category_id - Category ID (nullable)
 * @property {string} asset_type - Asset type (e.g., 'asset', 'deliverable')
 * @property {Array<Object>} filterable_schema - Array of filterable field definitions
 * @property {boolean} [is_multi_brand=false] - Whether this is a multi-brand context
 * @property {Object<string, Array>} [available_values={}] - Map of field_key to available values for current result set
 */

/**
 * Primary filter field keys (always visible)
 * These are system-level filters that are always shown
 */
const PRIMARY_FILTER_KEYS = {
    SEARCH: 'search',
    CATEGORY: 'category',
    ASSET_TYPE: 'asset_type',
    BRAND: 'brand',
};

/**
 * Check if a filter has available values
 * 
 * @param {Object} field - Filter field definition
 * @param {Object<string, Array>} availableValues - Map of field_key to available values
 * @returns {boolean} True if filter has available values
 */
function hasAvailableValues(field, availableValues = {}) {
    const fieldKey = field.field_key;
    
    // If no available values data provided, assume filter has values
    // (This allows the resolver to work without available values data)
    if (!availableValues || Object.keys(availableValues).length === 0) {
        return true;
    }
    
    const values = availableValues[fieldKey];
    
    // If field_key not in available values, assume it has values
    if (values === undefined) {
        return true;
    }
    
    // Check if values array exists and has items
    if (Array.isArray(values)) {
        return values.length > 0;
    }
    
    // For non-array values (e.g., count), check if > 0
    if (typeof values === 'number') {
        return values > 0;
    }
    
    // Default to true if value is truthy
    return !!values;
}

/**
 * Check if a filter is a primary filter
 * 
 * @param {Object} field - Filter field definition
 * @param {FilterTierConfig} config - Configuration object
 * @returns {boolean} True if filter is primary
 */
function isPrimaryFilter(field, config) {
    const fieldKey = field.field_key || field.key;
    
    // Search is always primary
    if (fieldKey === PRIMARY_FILTER_KEYS.SEARCH) {
        return true;
    }
    
    // Category is always primary
    if (fieldKey === PRIMARY_FILTER_KEYS.CATEGORY) {
        return true;
    }
    
    // Asset Type is always primary
    if (fieldKey === PRIMARY_FILTER_KEYS.ASSET_TYPE) {
        return true;
    }
    
    // Brand is primary only in multi-brand context
    if (fieldKey === PRIMARY_FILTER_KEYS.BRAND && config.is_multi_brand) {
        return true;
    }
    
    // Check if field has a "primary" flag
    // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
    // The field.is_primary value comes from MetadataSchemaResolver which computes effective_is_primary:
    // Resolution order: category override > global is_primary (deprecated) > false
    // Explicit check: only return true if explicitly set to true
    // false, null, undefined all return false (treated as secondary)
    const isPrimaryValue = field.is_primary === true;

    if (isPrimaryValue) {
        return true;
    }

    return false;
}

/**
 * Classify a single filter into a tier
 * 
 * @param {Object} field - Filter field definition
 * @param {FilterTierConfig} config - Configuration object
 * @returns {FilterClassification} Classification result
 */
function classifyFilter(field, config) {
    const fieldKey = field.field_key || field.key;
    const isPrimary = isPrimaryFilter(field, config);

    if (isPrimary) {
        return {
            field_key: field.field_key,
            tier: 'primary',
            field: field,
        };
    }

    return {
        field_key: field.field_key,
        tier: 'secondary',
        field: field,
    };
}

/**
 * Resolve filter tiers for all filters
 * 
 * This is the main entry point for classifying filters into tiers.
 * 
 * @param {FilterTierConfig} config - Configuration object
 * @returns {Object<FilterTier, Array<FilterClassification>>} Map of tier to filter classifications
 */
/** Set to true to log a single summary (e.g. window.__DEBUG_FILTER_TIERS__ = true) */
const isFilterTierDebugEnabled = () =>
    typeof window !== 'undefined' && (window.__DEBUG_FILTER_TIERS__ === true || window.__DEBUG_FILTERS__ === true);

export function resolveFilterTiers(config) {
    const {
        brand_id,
        category_id,
        asset_type,
        filterable_schema = [],
        is_multi_brand = false,
        available_values = {},
    } = config;

    // Validate required parameters
    if (!brand_id) {
        throw new Error('brand_id is required for filter tier resolution');
    }

    if (!asset_type) {
        throw new Error('asset_type is required for filter tier resolution');
    }

    const fullConfig = {
        brand_id,
        category_id,
        asset_type,
        is_multi_brand,
        available_values,
    };

    // Classify all filters
    const classifications = filterable_schema.map((field) =>
        classifyFilter(field, fullConfig)
    );

    // Group by tier
    const tiers = {
        primary: [],
        secondary: [],
        hidden: [],
    };

    classifications.forEach((classification) => {
        tiers[classification.tier].push(classification);
    });

    // Single consolidated debug log for troubleshooting (opt-in via window.__DEBUG_FILTER_TIERS__ or __DEBUG_FILTERS__)
    if (isFilterTierDebugEnabled()) {
        const summary = {
            filterTierResolver: {
                config: { brand_id, category_id, asset_type, is_multi_brand },
                tiers: {
                    primary: tiers.primary.map((c) => c.field_key),
                    secondary: tiers.secondary.map((c) => c.field_key),
                    hidden: tiers.hidden.map((c) => c.field_key),
                },
                classifications: classifications.map((c) => ({
                    field_key: c.field_key ?? c.field?.key ?? c.field?.field_key,
                    tier: c.tier,
                    is_primary: c.field?.is_primary,
                })),
                available_value_keys: Object.keys(available_values),
            },
        };
        console.log('[filterTierResolver] summary (set window.__DEBUG_FILTER_TIERS__ = false to disable)', summary);
    }

    return tiers;
}

/**
 * Get filters by tier
 * 
 * Convenience function to get filters for a specific tier
 * 
 * @param {FilterTierConfig} config - Configuration object
 * @param {FilterTier} tier - Tier to get filters for
 * @returns {Array<FilterClassification>} Filter classifications for the tier
 */
export function getFiltersByTier(config, tier) {
    const tiers = resolveFilterTiers(config);
    return tiers[tier] || [];
}

/**
 * Get primary filters
 * 
 * @param {FilterTierConfig} config - Configuration object
 * @returns {Array<FilterClassification>} Primary filter classifications
 */
export function getPrimaryFilters(config) {
    return getFiltersByTier(config, 'primary');
}

/**
 * Get secondary filters
 * 
 * @param {FilterTierConfig} config - Configuration object
 * @returns {Array<FilterClassification>} Secondary filter classifications
 */
export function getSecondaryFilters(config) {
    return getFiltersByTier(config, 'secondary');
}

/**
 * Get hidden filters
 * 
 * @param {FilterTierConfig} config - Configuration object
 * @returns {Array<FilterClassification>} Hidden filter classifications
 */
export function getHiddenFilters(config) {
    return getFiltersByTier(config, 'hidden');
}

/**
 * Check if a filter is in a specific tier
 * 
 * @param {Object} field - Filter field definition
 * @param {FilterTierConfig} config - Configuration object
 * @param {FilterTier} tier - Tier to check
 * @returns {boolean} True if filter is in the specified tier
 */
export function isFilterInTier(field, config, tier) {
    const classification = classifyFilter(field, config);
    return classification.tier === tier;
}

/**
 * Get tier for a specific filter
 * 
 * @param {Object} field - Filter field definition
 * @param {FilterTierConfig} config - Configuration object
 * @returns {FilterTier} The tier classification
 */
export function getFilterTier(field, config) {
    const classification = classifyFilter(field, config);
    return classification.tier;
}
