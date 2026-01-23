/**
 * FilterDescriptor Contract
 * 
 * Strict JS-level contract definition for filter descriptors.
 * This is a pure typing/documentation layer with zero runtime logic.
 * 
 * ⚠️ CONSTRAINTS:
 * - No backend changes
 * - No schema changes
 * - Pure frontend typing / documentation layer
 * - Used only as a contract reference
 * 
 * @module FilterDescriptor
 */

/**
 * Filter type enumeration
 * Defines the allowed filter input types
 * 
 * @typedef {'select' | 'multiselect' | 'text' | 'date' | 'range'} FilterType
 */

/**
 * FilterDescriptor
 * 
 * Complete contract definition for a filter descriptor.
 * All required fields must be present. Optional fields may be omitted.
 * 
 * @typedef {Object} FilterDescriptor
 * 
 * @property {string} key - Unique identifier for the filter (required)
 *   Example: 'color', 'orientation', 'created_date'
 * 
 * @property {string} label - Human-readable display label (required)
 *   Example: 'Color', 'Orientation', 'Created Date'
 * 
 * @property {FilterType} type - Filter input type (required)
 *   - 'select': Single selection from options
 *   - 'multiselect': Multiple selections from options
 *   - 'text': Text input (search/contains)
 *   - 'date': Date picker
 *   - 'range': Numeric or date range
 * 
 * @property {boolean} is_primary - Whether this filter is in the primary tier (required)
 *   Primary filters are always visible and not collapsed.
 *   Secondary filters are collapsed by default.
 * 
 * @property {boolean} is_global - Whether this filter applies globally across all contexts (required)
 *   Global filters are available regardless of category/asset_type scoping.
 *   Non-global filters are scoped to specific categories/asset_types.
 * 
 * @property {Array<number>|null} category_ids - Category IDs this filter applies to (required)
 *   - Array of category IDs: Filter applies only to these categories
 *   - null: Filter applies to all categories (when is_global is true)
 *   - Empty array []: Filter applies to no categories (effectively disabled)
 * 
 * @property {Array<string>|null} asset_types - Asset types this filter applies to (required)
 *   - Array of asset type strings: Filter applies only to these asset types
 *     Example: ['asset', 'marketing', 'ai_generated']
 *   - null: Filter applies to all asset types (when is_global is true)
 *   - Empty array []: Filter applies to no asset types (effectively disabled)
 * 
 * @property {string} [description] - Optional description/help text for the filter
 *   Provides additional context or guidance for users
 * 
 * @property {number} [sort_order] - Optional sort order for filter display
 *   Lower numbers appear first. If omitted, filters are sorted by label.
 * 
 * @property {string} [ui_group] - Optional UI grouping identifier
 *   Groups related filters together in the UI (e.g., 'technical', 'metadata', 'dates')
 */

/**
 * FilterDescriptorArray
 * 
 * Type alias for an array of FilterDescriptor objects
 * 
 * @typedef {Array<FilterDescriptor>} FilterDescriptorArray
 */

/**
 * PartialFilterDescriptor
 * 
 * Type alias for a partial FilterDescriptor (all fields optional)
 * Useful for filter updates or construction
 * 
 * @typedef {Partial<FilterDescriptor>} PartialFilterDescriptor
 */

// Export for JSDoc reference (no runtime exports needed)
// This file is purely for type documentation and contract definition
