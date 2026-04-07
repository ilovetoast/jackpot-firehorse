/**
 * Option editor utilities for metadata select/multiselect fields.
 * Option structure: { value, system_label, color?, icon? }
 */

/** Lowercase snake_case regex: lowercase letters, numbers, underscores only */
const SNAKE_CASE_REGEX = /^[a-z0-9_]+$/

/**
 * Convert snake_case to Title Case (e.g. high_quality → High Quality)
 * @param {string} input
 * @returns {string}
 */
export function snakeToTitleCase(input) {
    if (!input || typeof input !== 'string') return ''
    return input
        .trim()
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ')
}

/**
 * Convert input to lowercase snake_case (spaces/special chars → underscores)
 * @param {string} input
 * @returns {string}
 */
export function toSnakeCase(input) {
    if (!input || typeof input !== 'string') return ''
    return input
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_')
        .replace(/[^a-z0-9_]/g, '')
}

/**
 * Validate option value is lowercase snake_case
 * @param {string} value
 * @returns {{ valid: boolean, message?: string }}
 */
export function validateSnakeCase(value) {
    if (!value || typeof value !== 'string') {
        return { valid: false, message: 'Value is required' }
    }
    const normalized = toSnakeCase(value)
    if (normalized !== value.trim().toLowerCase()) {
        return { valid: false, message: 'Value must be lowercase snake_case (e.g. my_option)' }
    }
    if (!SNAKE_CASE_REGEX.test(normalized)) {
        return { valid: false, message: 'Value must contain only lowercase letters, numbers, and underscores' }
    }
    return { valid: true }
}

/**
 * Check for duplicate values in options array
 * @param {Array<{value: string}>} options
 * @param {string} newValue
 * @param {number} excludeIndex - Option index to exclude (when editing)
 * @returns {boolean}
 */
export function isDuplicateValue(options, newValue, excludeIndex = -1) {
    if (!options || !Array.isArray(options) || !newValue) return false
    const normalized = String(newValue).toLowerCase().trim()
    return options.some((opt, i) => i !== excludeIndex && String(opt.value).toLowerCase() === normalized)
}

/**
 * Normalize option for display (support both label and system_label)
 * @param {Object} opt
 * @returns {Object} { value, system_label, color?, icon? }
 */
export function normalizeOption(opt) {
    if (!opt) return null
    const system_label = opt.system_label ?? opt.label ?? opt.display_label ?? ''
    return {
        value: opt.value ?? '',
        system_label: typeof system_label === 'string' ? system_label : String(system_label),
        color: opt.color || null,
        icon: opt.icon || null,
    }
}

/**
 * Normalize options array for form state
 * @param {Array} options
 * @returns {Array}
 */
export function normalizeOptions(options) {
    if (!options || !Array.isArray(options)) return []
    return options.map(normalizeOption).filter((o) => o.value || o.system_label)
}

/**
 * Prepare options for API submission
 * @param {Array} options
 * @returns {Array<{value: string, system_label: string, color?: string, icon?: string}>}
 */
/**
 * Parse bulk-add lines (one per line) and append new options, skipping duplicates.
 * Used by MetadataFieldModal "Add options" and on Save so uncommitted textarea lines persist.
 *
 * @param {Array<{ value?: string, system_label?: string }>} existingOptions
 * @param {string} bulkAddText
 * @returns {{ options: Array, error: string|null }}
 */
export function mergeBulkAddTextIntoOptions(existingOptions, bulkAddText) {
    const raw = typeof bulkAddText === 'string' ? bulkAddText : ''
    const lines = raw.split('\n').map((l) => l.trim()).filter(Boolean)
    if (lines.length === 0) {
        return { options: existingOptions || [], error: null }
    }

    const existingValues = new Set((existingOptions || []).map((o) => String(o.value).toLowerCase()))
    const toAdd = []
    for (const line of lines) {
        const value =
            toSnakeCase(line) || line.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '')
        if (!value) {
            continue
        }
        const snakeCheck = validateSnakeCase(value)
        if (!snakeCheck.valid) {
            return { options: existingOptions || [], error: snakeCheck.message || 'Invalid option value' }
        }
        if (existingValues.has(value)) {
            continue
        }
        existingValues.add(value)
        toAdd.push({ value, system_label: snakeToTitleCase(value) })
    }
    if (toAdd.length === 0) {
        return {
            options: existingOptions || [],
            error: 'All lines in the bulk box already exist or could not be used as values.',
        }
    }
    return { options: [...(existingOptions || []), ...toAdd], error: null }
}

export function prepareOptionsForSubmit(options) {
    if (!options || !Array.isArray(options)) return []
    return options
        .filter((o) => o && (o.value || o.system_label))
        .map((o) => {
            const system_label = String(o.system_label ?? o.label ?? '').trim()
            const out = {
                value: String(o.value || '').trim().toLowerCase(),
                system_label,
                label: system_label, // Backend accepts 'label' for system_label
            }
            if (o.color && /^#[0-9A-Fa-f]{6}$/.test(o.color)) {
                out.color = o.color
            }
            if (o.icon && typeof o.icon === 'string') {
                out.icon = o.icon.trim()
            }
            return out
        })
}
