/**
 * Widget Resolver
 *
 * Centralized metadata widget resolution. Single source of truth for display architecture.
 * Eliminates scattered key checks (quality_rating, collection, tags, etc.).
 *
 * Resolution order:
 * 1. field.type === 'rating' → RATING
 * 2. field.display_widget === 'toggle' → TOGGLE
 * 3. field.key === 'collection' → COLLECTION_BADGES
 * 4. field.key === 'tags' → TAG_MANAGER
 * 5. field.key === 'dominant_hue_group' → COLOR_SWATCH
 * 6. field.key === 'dominant_colors' → DOMINANT_COLORS (display swatches)
 * 7. Type-based fallback → STANDARD (or specific type)
 *
 * @module widgetResolver
 */

/** Context: where the field is being rendered */
export const CONTEXT = {
    FILTER: 'filter',
    EDIT: 'edit',
    DISPLAY: 'display',
}

/** Canonical widget types — use these in switch(widget) */
export const WIDGET = {
    RATING: 'rating',
    TOGGLE: 'toggle',
    COLOR_SWATCH: 'color_swatch',
    TAG_MANAGER: 'tag_manager',
    COLLECTION_BADGES: 'collection_badges',
    DOMINANT_COLORS: 'dominant_colors',
    STANDARD: 'standard',
    EXCLUDED: 'excluded',
    // Filter/display-specific (resolved from above + context)
    EXPIRATION_DATE: 'expiration_date',
    DROPDOWN: 'dropdown',
    MULTISELECT: 'multiselect',
    CHECKBOX: 'checkbox',
    TEXT: 'text',
    NUMBER: 'number',
    DATE: 'date',
    TEXTAREA: 'textarea',
}

/** Legacy aliases for backward compatibility */
export const WIDGET_ALIASES = {
    STAR_RATING: WIDGET.RATING,
    SWATCH_FILTER: WIDGET.COLOR_SWATCH,
    TAG_FILTER: WIDGET.TAG_MANAGER,
    COLLECTION_DROPDOWN: WIDGET.COLLECTION_BADGES,
    DOMINANT_COLORS_SWATCHES: WIDGET.DOMINANT_COLORS,
    DOMINANT_COLORS_FILTER: WIDGET.DOMINANT_COLORS,
}

/** Field keys excluded from generic metadata loop (dimensions only; tags/collection use dedicated rows) */
const EXCLUDED_KEYS = ['dimensions']

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
 * Resolve widget type for a field in a given context.
 * Centralized — no direct key checks in components.
 *
 * @param {Object} field - { key, field_key, type, display_widget, filter_type, ... }
 * @param {string} context - CONTEXT.DISPLAY | CONTEXT.EDIT | CONTEXT.FILTER
 * @returns {string} WIDGET.*
 */
export function resolve(field, context) {
    const key = getFieldKey(field)
    const type = field?.type || 'text'
    const displayWidget = field?.display_widget
    const filterType = field?.filter_type

    // dimensions: always excluded
    if (EXCLUDED_KEYS.includes(key)) {
        return WIDGET.EXCLUDED
    }

    // 1. type === 'rating' → RATING
    if (type === 'rating') {
        return WIDGET.RATING
    }

    // quality_rating (legacy type) or display_widget=stars
    if (key === 'quality_rating' || displayWidget === 'stars') {
        return WIDGET.RATING
    }

    // 2. display_widget === 'toggle' → TOGGLE
    if (displayWidget === 'toggle' || (type === 'boolean' && key === 'starred')) {
        return WIDGET.TOGGLE
    }

    // 3. key === 'collection' → COLLECTION_BADGES
    if (key === 'collection') {
        return WIDGET.COLLECTION_BADGES
    }

    // 4. key === 'tags' → TAG_MANAGER
    if (key === 'tags') {
        return WIDGET.TAG_MANAGER
    }

    // 5. key === 'dominant_hue_group' → COLOR_SWATCH (filter context)
    if (key === 'dominant_hue_group') {
        if (context === CONTEXT.FILTER && (filterType === 'color' || true)) {
            return WIDGET.COLOR_SWATCH
        }
        return WIDGET.EXCLUDED
    }

    // 6. key === 'dominant_colors' → DOMINANT_COLORS
    if (key === 'dominant_colors') {
        return WIDGET.DOMINANT_COLORS
    }

    // expiration_date: preset dropdown (filter context)
    if (key === 'expiration_date' && context === CONTEXT.FILTER) {
        return WIDGET.EXPIRATION_DATE
    }

    // filter_type === 'color' (e.g. dominant_hue_group from backend)
    if (context === CONTEXT.FILTER && filterType === 'color') {
        return WIDGET.COLOR_SWATCH
    }

    // Type-based fallback (STANDARD variants)
    switch (type) {
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
 * Check if field should be excluded from generic metadata loop.
 * Tags and collection are rendered in dedicated rows; dimensions excluded.
 * @param {Object} field
 * @returns {boolean}
 */
export function isExcludedFromGenericLoop(field) {
    const w = resolve(field, CONTEXT.DISPLAY)
    return w === WIDGET.EXCLUDED || w === WIDGET.TAG_MANAGER || w === WIDGET.COLLECTION_BADGES
}

/**
 * Check if field uses Rating widget
 */
export function isStarRating(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.RATING
}

/**
 * Check if field uses Toggle widget
 */
export function isToggle(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.TOGGLE
}

/**
 * Check if field uses DominantColors swatches (display context)
 */
export function isDominantColorsSwatches(field, context = CONTEXT.DISPLAY) {
    return resolve(field, context) === WIDGET.DOMINANT_COLORS
}

/**
 * Check if field uses ColorSwatch filter (filter context)
 */
export function isSwatchFilter(field, context = CONTEXT.FILTER) {
    return resolve(field, context) === WIDGET.COLOR_SWATCH
}

/**
 * Check if schema has collection field (for dedicated collection row)
 */
export function hasCollectionField(fields) {
    return Array.isArray(fields) && fields.some((f) => resolve(f, CONTEXT.DISPLAY) === WIDGET.COLLECTION_BADGES)
}

/**
 * Get human-readable label for fields that use a custom display widget.
 * Returns null if the field uses standard type-based rendering.
 * Used in Edit Metadata Field modal to show "Custom display: Rating" etc.
 *
 * @param {Object} field - { key, field_key, type, display_widget, ... }
 * @returns {string|null} e.g. "Rating", "Collection", "Tags", "Toggle", "Color Swatch", "Dominant Colors"
 */
export function getCustomDisplayLabel(field) {
    const key = getFieldKey(field)
    const displayWidget = field?.display_widget
    const type = field?.type || 'text'

    if (key === 'quality_rating' || displayWidget === 'stars' || type === 'rating') return 'Rating'
    if (key === 'collection') return 'Collection'
    if (key === 'tags') return 'Tags'
    if (key === 'dominant_hue_group') return 'Dominant Hue'
    if (key === 'dominant_colors') return 'Dominant Colors'
    if (displayWidget === 'toggle' || (type === 'boolean' && key === 'starred')) return 'Toggle'

    return null
}
