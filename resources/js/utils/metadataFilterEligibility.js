/**
 * Phase 5: which field types may use sidebar / primary filters (frontend mirror of App\Support\MetadataFieldFilterEligibility).
 */

export function normalizeFieldType(type) {
    const t = String(type ?? 'text').toLowerCase()
    return t === 'multi_select' ? 'multiselect' : t
}

export function canUseSidebarFilter(type) {
    const t = normalizeFieldType(type)
    return ['select', 'multiselect', 'boolean', 'date'].includes(t)
}

export function canUsePrimaryFilter(type) {
    return canUseSidebarFilter(type)
}

export function canUseSearch(type) {
    const t = normalizeFieldType(type)
    return ['text', 'textarea', 'select', 'multiselect', 'boolean', 'date', 'number'].includes(t)
}

/**
 * Coerce filter flags for a given type (form state / before save).
 */
export function sanitizeFilterFlagsForForm(type, showInFilters, isPrimary) {
    const t = normalizeFieldType(type)
    if (!canUseSidebarFilter(t)) {
        return { show_in_filters: false, is_primary: false }
    }
    let s = !!showInFilters
    let p = !!isPrimary
    if (!s) {
        p = false
    }
    if (p) {
        s = true
    }
    return { show_in_filters: s, is_primary: p }
}

export function whereAppearsFilterHint(type) {
    const t = normalizeFieldType(type)
    if (t === 'text' || t === 'textarea') {
        return 'Text fields are found through the main asset search instead of sidebar filters.'
    }
    if (t === 'number') {
        return 'Number fields are available for details and upload. Range filtering can be added later.'
    }
    if (canUseSidebarFilter(t)) {
        return 'This field type works well as a sidebar or primary filter.'
    }
    return null
}

export function ineligibleFilterRowNote(type) {
    const t = normalizeFieldType(type)
    if (t === 'text' || t === 'textarea') {
        return 'This field type uses main search instead of sidebar filters.'
    }
    if (t === 'number') {
        return 'Number fields are not available as sidebar filters yet.'
    }
    return null
}
