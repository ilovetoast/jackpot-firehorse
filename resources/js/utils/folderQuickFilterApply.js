/**
 * Phase 4.1 — pure URL-mutation helpers for the folder quick filter flyout.
 *
 * Extracting these out of the React component lets us:
 *   1. Test the URL contract without rendering anything.
 *   2. Guarantee that quick-filter mutations use the same canonical helpers
 *      (filtersToFlatParams / buildUrlParamsWithFlatFilters) as the rest of
 *      the filter system. There is no quick-filter-specific URL grammar.
 *   3. Make the round-trip easier to inspect when ?debug=qf-filters is set.
 *
 * Important contract bug fixed in Phase 4.1:
 *   - filterable_schema is built per-category and excludes fields with
 *     `is_filter_hidden=true`. A quick filter for such a field would be a
 *     valid SIDEBAR row (because eligibility uses `is_filterable`, not the
 *     per-category visibility) but its key would be MISSING from the schema's
 *     filterKeys. `buildUrlParamsWithFlatFilters(filterKeys, ...)` silently
 *     drops keys not in `filterKeys`, so the apply was a no-op.
 *   - We now ALWAYS union the active quick filter's `field_key` into
 *     filterKeys before serializing.
 */

import {
    buildUrlParamsWithFlatFilters,
    parseFiltersFromUrl,
} from './filterUrlUtils.js'

/**
 * Union the quick filter's own field key into the schema-derived filterKeys.
 * Eliminates the silent-drop bug described above.
 *
 * @param {string[]|undefined|null} schemaKeys
 * @param {string} fieldKey
 * @returns {string[]}
 */
export function buildFilterKeysForQuickFilter(schemaKeys, fieldKey) {
    const base = Array.isArray(schemaKeys) ? schemaKeys.filter(Boolean) : []
    if (!fieldKey) return base
    if (base.includes(fieldKey)) return base
    return [...base, fieldKey]
}

/**
 * Parse the currently-selected values for a single field from a URL search string.
 * Returns an array of string-valued tokens (multi-select round-trips as []).
 *
 * @param {string} searchString - `window.location.search` (with or without `?`)
 * @param {string} fieldKey
 * @param {string[]} filterKeys
 * @returns {string[]}
 */
export function selectedValuesFromUrl(searchString, fieldKey, filterKeys) {
    const raw = searchString?.startsWith?.('?')
        ? searchString.slice(1)
        : searchString || ''
    const search = new URLSearchParams(raw)
    const parsed = parseFiltersFromUrl(search, filterKeys)
    const entry = parsed[fieldKey]
    if (!entry || entry.value === undefined || entry.value === null) return []
    return Array.isArray(entry.value)
        ? entry.value.filter((v) => v !== '' && v !== null && v !== undefined).map(String)
        : entry.value === ''
          ? []
          : [String(entry.value)]
}

/**
 * Apply a single field-level mutation by reading the current URL, transforming
 * the parsed filters in place, and emitting the next flat URL params. Pure —
 * does NOT call router. The caller (the flyout component) handles `router.get`.
 *
 * @param {string} searchString
 * @param {string} fieldKey
 * @param {(filters: Record<string, {operator: string, value: unknown}>) => void} mutator
 * @param {string[]} filterKeys - SCHEMA keys + the quick-filter field key.
 * @param {{ preserveQueryKeys?: string[] }} [options]
 * @returns {Record<string, string | string[]>} flat URL params for router.get
 */
export function buildNextParamsForQuickFilter(
    searchString,
    fieldKey,
    mutator,
    filterKeys,
    options = {}
) {
    const raw = searchString?.startsWith?.('?')
        ? searchString.slice(1)
        : searchString || ''
    const urlParams = new URLSearchParams(raw)
    const current = parseFiltersFromUrl(urlParams, filterKeys)
    // Defensive shallow clone so mutator-in-array tweaks don't poison the
    // parser's returned objects.
    const next = { ...current }
    mutator(next, fieldKey)
    return buildUrlParamsWithFlatFilters(urlParams, next, filterKeys, options)
}

/**
 * Multi-select toggle: add value if absent, remove if present. If the array
 * empties out, drop the key entirely (matches AssetGridSecondaryFilters).
 *
 * @param {Record<string, {operator: string, value: unknown}>} draft
 * @param {string} fieldKey
 * @param {string} valueToToggle
 */
export function applyMultiselectToggle(draft, fieldKey, valueToToggle) {
    const value = String(valueToToggle)
    const existing = draft[fieldKey]?.value
    const arr = Array.isArray(existing)
        ? existing.map(String)
        : existing !== undefined && existing !== null && existing !== ''
          ? [String(existing)]
          : []
    const has = arr.includes(value)
    const updated = has ? arr.filter((v) => v !== value) : [...arr, value]
    if (updated.length === 0) {
        delete draft[fieldKey]
    } else {
        draft[fieldKey] = { operator: 'equals', value: updated }
    }
}

/**
 * Single-select set: assign or clear when the same value is reclicked.
 *
 * @param {Record<string, {operator: string, value: unknown}>} draft
 * @param {string} fieldKey
 * @param {string} valueToSet
 */
export function applySingleSelect(draft, fieldKey, valueToSet) {
    const value = String(valueToSet)
    const existing = draft[fieldKey]?.value
    const same =
        existing !== undefined &&
        !Array.isArray(existing) &&
        String(existing) === value
    if (same) {
        delete draft[fieldKey]
    } else {
        draft[fieldKey] = { operator: 'equals', value }
    }
}

/**
 * Boolean set: stores 'true'/'false' string (matches FilterFieldInput
 * boolean widget). Reclicking the same value clears.
 *
 * @param {Record<string, {operator: string, value: unknown}>} draft
 * @param {string} fieldKey
 * @param {boolean} boolValue
 */
export function applyBoolean(draft, fieldKey, boolValue) {
    const value = boolValue ? 'true' : 'false'
    const existing = draft[fieldKey]?.value
    const same =
        existing !== undefined &&
        !Array.isArray(existing) &&
        String(existing) === value
    if (same) {
        delete draft[fieldKey]
    } else {
        draft[fieldKey] = { operator: 'equals', value }
    }
}
