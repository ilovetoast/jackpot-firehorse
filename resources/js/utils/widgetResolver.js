/**
 * Widget Resolver
 *
 * Centralized metadata widget rendering logic. Determines which component to use
 * based on display_widget (highest priority), type (fallback), and context.
 *
 * Rules:
 * - rating → StarRating
 * - toggle → ToggleSwitch
 * - color → SwatchFilter (filters only)
 * - select → Dropdown
 * - multiselect → MultiSelect
 * - boolean → Checkbox (unless display_widget === 'toggle')
 * - dominant_color_bucket: filter context only, ColorSwatchFilter
 * - dominant_colors: display context only, DominantColorsSwatches
 * - collection & tags: rendered in dedicated rows, excluded from generic metadata loop
 *
 * @module widgetResolver
 */

/** Context: where the field is being rendered */
export const CONTEXT = {
    FILTER: 'filter',
    EDIT: 'edit',
    DISPLAY: 'display',
}

/** Widget types returned by resolve() */
export const WIDGET = {
    STAR_RATING: 'star_rating',
    TOGGLE: 'toggle',
    SWATCH_FILTER: 'swatch_filter',
    DROPDOWN: 'dropdown',
    MULTISELECT: 'multiselect',
    CHECKBOX: 'checkbox',
    DOMINANT_COLORS_SWATCHES: 'dominant_colors_swatches',
    DOMINANT_COLORS_FILTER: 'dominant_colors_filter',
    TAG_FILTER: 'tag_filter',
    COLLECTION_DROPDOWN: 'collection_dropdown',
    EXPIRATION_DATE: 'expiration_date',
    TEXT: 'text',
    NUMBER: 'number',
    DATE: 'date',
    TEXTAREA: 'textarea',
    EXCLUDED: 'excluded',
}

/** Fields excluded from generic metadata loop (rendered in dedicated rows) */
export const EXCLUDED_FROM_GENERIC_LOOP = ['tags', 'collection', 'dimensions']

/** Special field keys that have context-specific rendering */
const SPECIAL_KEYS = {
    dominant_color_bucket: 'dominant_color_bucket',
    dominant_colors: 'dominant_colors',
    quality_rating: 'quality_rating',
    starred: 'starred',
    collection: 'collection',
    tags: 'tags',
    expiration_date: 'expiration_date',
}

/**
 * Get the field key from a field object (handles both key and field_key)
 * @param {Object} field
 * @returns {string|null}
 */
function getFieldKey(field) {
    if (!field) return null
    return field.key ?? field.field_key ?? null
}

/**
 * Resolve widget type for display context (AssetMetadataDisplay, AssetDetailPanel)
 *
 * @param {Object} field - { key, field_key, type, display_widget, ... }
 * @param {string} context - CONTEXT.DISPLAY | CONTEXT.EDIT | CONTEXT.FILTER
 * @returns {string} WIDGET.*
 */
export function resolve(field, context) {
    const key = getFieldKey(field)
    const type = field?.type || 'text'
    const displayWidget = field?.display_widget
    const filterType = field?.filter_type

    // Excluded: collection & tags rendered in dedicated rows
    if (EXCLUDED_FROM_GENERIC_LOOP.includes(key)) {
        return WIDGET.EXCLUDED
    }

    // dominant_color_bucket: filter context only, use ColorSwatchFilter
    if (key === SPECIAL_KEYS.dominant_color_bucket) {
        if (context === CONTEXT.FILTER && filterType === 'color') {
            return WIDGET.SWATCH_FILTER
        }
        return WIDGET.EXCLUDED
    }

    // dominant_colors: display context only, use DominantColorsSwatches
    if (key === SPECIAL_KEYS.dominant_colors) {
        if (context === CONTEXT.DISPLAY) {
            return WIDGET.DOMINANT_COLORS_SWATCHES
        }
        if (context === CONTEXT.FILTER) {
            return WIDGET.DOMINANT_COLORS_FILTER
        }
        return WIDGET.EXCLUDED
    }

    // quality_rating: StarRating (display + edit)
    if (key === SPECIAL_KEYS.quality_rating || type === 'rating' || displayWidget === 'stars') {
        return WIDGET.STAR_RATING
    }

    // starred / boolean with display_widget=toggle: ToggleSwitch
    if (key === SPECIAL_KEYS.starred || (type === 'boolean' && displayWidget === 'toggle')) {
        return WIDGET.TOGGLE
    }

    // collection: dedicated dropdown (filter context)
    if (key === SPECIAL_KEYS.collection && context === CONTEXT.FILTER) {
        return WIDGET.COLLECTION_DROPDOWN
    }

    // tags: dedicated tag filter (filter context)
    if (key === SPECIAL_KEYS.tags && context === CONTEXT.FILTER) {
        return WIDGET.TAG_FILTER
    }

    // expiration_date: preset dropdown (filter context)
    if (key === SPECIAL_KEYS.expiration_date && context === CONTEXT.FILTER) {
        return WIDGET.EXPIRATION_DATE
    }

    // Color swatch filter: filter_type === 'color' (e.g. dominant_color_bucket from backend)
    if (context === CONTEXT.FILTER && filterType === 'color') {
        return WIDGET.SWATCH_FILTER
    }

    // display_widget overrides type
    if (displayWidget === 'toggle' && type === 'boolean') {
        return WIDGET.TOGGLE
    }

    // Type-based fallback
    switch (type) {
        case 'rating':
            return WIDGET.STAR_RATING
        case 'select':
            return WIDGET.DROPDOWN
        case 'multiselect':
            return WIDGET.MULTISELECT
        case 'boolean':
            return WIDGET.CHECKBOX
        case 'number':
            return WIDGET.NUMBER
        case 'date':
            return WIDGET.DATE
        case 'textarea':
            return WIDGET.TEXTAREA
        case 'text':
        default:
            return WIDGET.TEXT
    }
}

/**
 * Check if field should be excluded from generic metadata loop
 * @param {Object} field
 * @returns {boolean}
 */
export function isExcludedFromGenericLoop(field) {
    const key = getFieldKey(field)
    return EXCLUDED_FROM_GENERIC_LOOP.includes(key)
}

/**
 * Check if field uses StarRating widget
 * @param {Object} field
 * @param {string} context
 * @returns {boolean}
 */
export function isStarRating(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.STAR_RATING
}

/**
 * Check if field uses ToggleSwitch widget
 * @param {Object} field
 * @param {string} context
 * @returns {boolean}
 */
export function isToggle(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.TOGGLE
}

/**
 * Check if field uses DominantColorsSwatches (display context only)
 * @param {Object} field
 * @param {string} context
 * @returns {boolean}
 */
export function isDominantColorsSwatches(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.DOMINANT_COLORS_SWATCHES
}

/**
 * Check if field uses ColorSwatchFilter (filter context)
 * @param {Object} field
 * @param {string} context
 * @returns {boolean}
 */
export function isSwatchFilter(field, context = CONTEXT.FILTER) {
    return resolve(field, context) === WIDGET.SWATCH_FILTER
}
