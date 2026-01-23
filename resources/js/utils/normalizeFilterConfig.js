/**
 * Filter Config Normalizer
 * 
 * Pure deterministic transformation for normalizing Inertia props into
 * a consistent filter configuration compatible with resolveFilterTiers.
 * 
 * ⚠️ CONSTRAINTS:
 * - NO backend changes
 * - NO schema changes
 * - NO UI rendering
 * - Pure deterministic transformation
 * - Additive only
 * - DO NOT mutate input props
 * 
 * This normalizer ensures that raw Inertia props are transformed into
 * a consistent shape that resolveFilterTiers expects, handling edge cases
 * and providing safe defaults.
 * 
 * @module normalizeFilterConfig
 */

/**
 * Raw Inertia props that may be inconsistent
 * 
 * @typedef {Object} RawFilterProps
 * @property {Object} [auth] - Authentication context
 * @property {Object} [auth.activeBrand] - Active brand object
 * @property {number} [auth.activeBrand.id] - Active brand ID
 * @property {Array} [auth.brands] - Array of brand objects
 * @property {number|string|null|undefined} [selected_category] - Selected category ID or "all"
 * @property {number|null|undefined} [category_id] - Category ID (alternative prop name)
 * @property {string} [asset_type] - Asset type string
 * @property {Array} [filterable_schema] - Filterable schema array
 * @property {Object} [available_values] - Available filter values map
 */

/**
 * Normalized filter configuration
 * Compatible with resolveFilterTiers from filterTierResolver
 * 
 * @typedef {Object} NormalizedFilterConfig
 * @property {number|null} brand_id - Brand ID (always present, may be null if activeBrand not available)
 *   Note: resolveFilterTiers requires brand_id to be truthy and will throw if null
 * @property {string} asset_type - Asset type (always present, defaults to 'asset')
 * @property {number|null} category_id - Category ID (always present, null for "All Categories")
 * @property {Array} filterable_schema - Filterable schema array (always present, defaults to [])
 * @property {Object<string, Array>} available_values - Available values map (always present, defaults to {})
 * @property {boolean} is_multi_brand - Whether multi-brand context (always present, inferred from brands)
 */

/**
 * Normalize raw Inertia props into consistent filter configuration
 * 
 * This function ensures all required keys exist and handles edge cases:
 * - "All Categories" → category_id = null
 * - Undefined available_values → {}
 * - Undefined filterable_schema → []
 * - Infers is_multi_brand from brands array length
 * 
 * @param {RawFilterProps} rawProps - Raw Inertia props (may be inconsistent)
 * @returns {NormalizedFilterConfig} Normalized configuration compatible with resolveFilterTiers
 * 
 * @example
 * ```javascript
 * import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
 * import { resolveFilterTiers } from '../utils/filterTierResolver'
 * import { usePage } from '@inertiajs/react'
 * 
 * function MyComponent() {
 *   const pageProps = usePage().props
 *   
 *   // Normalize raw Inertia props
 *   const normalized = normalizeFilterConfig({
 *     auth: pageProps.auth,
 *     selected_category: pageProps.selected_category,
 *     asset_type: 'asset',
 *     filterable_schema: pageProps.filterable_schema,
 *     available_values: pageProps.available_values,
 *   })
 *   
 *   // Use normalized config with resolveFilterTiers
 *   // Output is directly compatible - no additional transformation needed
 *   const tiers = resolveFilterTiers(normalized)
 *   
 *   // tiers.primary, tiers.secondary, tiers.hidden are now available
 * }
 * ```
 * 
 * @example
 * ```javascript
 * // Handles edge cases automatically:
 * normalizeFilterConfig({
 *   auth: { activeBrand: { id: 1 }, brands: [{ id: 1 }, { id: 2 }] },
 *   selected_category: 'all',  // → category_id: null
 *   // filterable_schema missing → defaults to []
 *   // available_values missing → defaults to {}
 * })
 * // Returns: {
 * //   brand_id: 1,
 * //   asset_type: 'asset',
 * //   category_id: null,
 * //   filterable_schema: [],
 * //   available_values: {},
 * //   is_multi_brand: true
 * // }
 * ```
 */
export function normalizeFilterConfig(rawProps = {}) {
    // Extract auth context safely
    const auth = rawProps.auth || {};
    const activeBrand = auth.activeBrand || null;
    const brands = auth.brands || [];
    
    // Normalize brand_id
    // Extract from activeBrand.id, fallback to null if not available
    // Note: resolveFilterTiers requires brand_id to be truthy and will throw if null
    // This normalizer ensures the key exists (even if null) for consistent shape
    const brand_id = activeBrand?.id ?? null;
    
    // Normalize category_id
    // Handle multiple possible prop names and edge cases
    let category_id = null;
    
    // Check for selected_category prop (most common)
    if (rawProps.selected_category !== undefined && rawProps.selected_category !== null) {
        // Handle "all" string case (case-insensitive)
        if (typeof rawProps.selected_category === 'string' && rawProps.selected_category.toLowerCase() === 'all') {
            category_id = null;
        }
        // Handle numeric string or number
        else if (rawProps.selected_category !== '' && rawProps.selected_category !== 'all') {
            const parsed = parseInt(rawProps.selected_category, 10);
            category_id = isNaN(parsed) ? null : parsed;
        }
    }
    // Check for category_id prop (alternative name)
    else if (rawProps.category_id !== undefined && rawProps.category_id !== null) {
        if (typeof rawProps.category_id === 'string' && rawProps.category_id.toLowerCase() === 'all') {
            category_id = null;
        } else if (rawProps.category_id !== '' && rawProps.category_id !== 'all') {
            const parsed = parseInt(rawProps.category_id, 10);
            category_id = isNaN(parsed) ? null : parsed;
        }
    }
    // Default to null (All Categories)
    
    // Normalize asset_type
    // Default to 'asset' if not provided (most common case)
    const asset_type = rawProps.asset_type || 'asset';
    
    // Normalize filterable_schema
    // Ensure it's always an array, default to empty array
    let filterable_schema = [];
    if (Array.isArray(rawProps.filterable_schema)) {
        filterable_schema = rawProps.filterable_schema;
    }
    // If undefined, null, or not an array, use empty array
    
    // Normalize available_values
    // Ensure it's always an object, default to empty object
    let available_values = {};
    if (rawProps.available_values && typeof rawProps.available_values === 'object' && !Array.isArray(rawProps.available_values)) {
        // Only copy if it's a plain object (not array, not null)
        available_values = rawProps.available_values;
    }
    // If undefined, null, array, or not an object, use empty object
    
    // Infer is_multi_brand safely
    // true only if brands is an array with length > 1
    // Filter out disabled brands for accurate count (matching AppBrandLogo logic)
    const validBrands = Array.isArray(brands) 
        ? brands.filter((brand) => !brand.is_disabled)
        : [];
    const is_multi_brand = validBrands.length > 1;
    
    // Return normalized configuration
    // All fields are guaranteed to exist with safe defaults
    return {
        brand_id,
        asset_type,
        category_id,
        filterable_schema,
        available_values,
        is_multi_brand,
    };
}

/**
 * Validate normalized config has all required fields
 * 
 * Internal validation helper (not exported, for development/debugging)
 * 
 * @param {NormalizedFilterConfig} config - Normalized config to validate
 * @returns {boolean} True if config is valid
 */
function validateNormalizedConfig(config) {
    const required = ['brand_id', 'asset_type', 'category_id', 'filterable_schema', 'available_values', 'is_multi_brand'];
    return required.every(key => key in config);
}
