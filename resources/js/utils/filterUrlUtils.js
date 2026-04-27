/**
 * Filter URL utilities: readable flat query params for asset filters.
 *
 * - Serialize: filters object → flat params (e.g. photo_type=action&scene_classification=product)
 * - Parse: URL search params → filters object
 *
 * Reserved query param names (never treated as filter keys):
 */
const RESERVED_PARAMS = new Set([
  'category',
  'sort',
  'sort_direction',
  'lifecycle',
  'uploaded_by',
  'file_type',
  'asset',
  'edit_metadata',
  'page',
  'filters',
  'missing_metadata',
  'missing_tags',
  'pending_suggestions',
])

/** Keys that must be included in URL for load_more / infinite scroll to respect filters (backend applies these even if not in schema). */
const SPECIAL_FILTER_KEYS = ['tags', 'collection']

/** Keys that support multiple values in the URL (repeated param or dominant_hue_group[]=X). Backend accepts array for these. */
export const MULTI_VALUE_FILTER_KEYS = new Set(['tags', 'collection', 'dominant_hue_group'])

/**
 * Normalize a filter param value to a deduplicated array.
 * Handles: array, string, PHP-style duplicated keys.
 *
 * @param {unknown} value - Raw value from URL/state
 * @returns {string[]} Deduplicated array of string values
 */
export function normalizeFilterParam(value) {
  if (Array.isArray(value)) {
    return [...new Set(value.map(String).filter(Boolean))]
  }
  if (typeof value === 'string') {
    return [value]
  }
  return []
}

/**
 * @param {Record<string, { operator?: string, value?: unknown }>} filters
 * @param {string[]} [filterKeys] - If provided, only include these keys (e.g. from filterable_schema)
 * @returns {Record<string, string | string[]>} Flat params for URL (key → string or string[] for multi-value)
 */
export function filtersToFlatParams(filters, filterKeys = null) {
  if (!filters || typeof filters !== 'object') return {}
  const keys = filterKeys ? [...new Set([...filterKeys, ...SPECIAL_FILTER_KEYS])] : Object.keys(filters)
  const out = {}
  for (const key of keys) {
    if (RESERVED_PARAMS.has(key)) continue
    const def = filters[key]
    if (!def || (def.value === undefined && def.value === null)) continue
    const v = def.value
    if (Array.isArray(v)) {
      const nonEmpty = [...new Set(v.filter(x => x !== '' && x !== null && x !== undefined).map(String))]
      if (nonEmpty.length > 0) {
        // Keep all values for URL when multiple are selected (e.g. multiselect product filters), not just the first.
        // MULTI_VALUE_FILTER_KEYS (tags, collection, …) and any key with 2+ values get the full list.
        out[key] = MULTI_VALUE_FILTER_KEYS.has(key) || nonEmpty.length > 1 ? nonEmpty : [nonEmpty[0]]
      }
    } else if (v !== '' && v !== null && v !== undefined) {
      out[key] = String(v)
    }
  }
  return out
}

/**
 * Extract base key from PHP-style array param (e.g. dominant_hue_group[0] -> dominant_hue_group)
 * @param {string} key
 * @param {Set<string>} keySet
 * @returns {string|null} Base key if valid, else null
 */
function parsePhpArrayKey(key, keySet) {
  const match = key.match(/^(.+)\[\d*\]$/)
  if (match) {
    const base = match[1]
    return keySet.has(base) ? base : null
  }
  return keySet.has(key) ? key : null
}

/**
 * @param {URLSearchParams|Record<string, string>} params - URL params or object
 * @param {string[]} filterKeys - Allowed filter keys (e.g. from filterable_schema); only these are read
 * @param {{ navigationKeys?: string[] }} [options] - Query keys that are page navigation (e.g. Collections `collection`, `category_id`) — never treated as metadata filters
 * @returns {Record<string, { operator: string, value: string | string[] }>} filters object (multi-value keys get value as string[])
 */
export function flatParamsToFilters(params, filterKeys = [], options = {}) {
  const navigationKeys = new Set(options.navigationKeys || [])
  const keySet = new Set([...(filterKeys || []), ...SPECIAL_FILTER_KEYS, 'dominant_hue_group'])
  if (keySet.size === 0) return {}
  const entries = params instanceof URLSearchParams
    ? Array.from(params.entries())
    : Object.entries(params || {})
  const rawByKey = {}
  for (const [key, value] of entries) {
    if (RESERVED_PARAMS.has(key) || navigationKeys.has(key)) continue
    if (value === '' || value === null || value === undefined) continue
    const baseKey = parsePhpArrayKey(key, keySet) ?? (keySet.has(key) ? key : null)
    if (!baseKey) continue
    if (!rawByKey[baseKey]) rawByKey[baseKey] = []
    rawByKey[baseKey].push(String(value))
  }
  const out = {}
  for (const [baseKey, values] of Object.entries(rawByKey)) {
    const isMulti = MULTI_VALUE_FILTER_KEYS.has(baseKey) || values.length > 1
    const normalized = isMulti ? [...new Set(values)] : values[values.length - 1]
    out[baseKey] = { operator: 'equals', value: normalized }
  }
  return out
}

/**
 * Parse filters from URL: prefer flat params, fallback to legacy 'filters' JSON.
 *
 * @param {URLSearchParams} urlParams
 * @param {string[]} filterKeys - From filterable_schema (field_key)
 * @param {{ navigationKeys?: string[] }} [options] - Passed to flatParamsToFilters
 * @returns {Record<string, { operator: string, value: unknown }>}
 */
export function parseFiltersFromUrl(urlParams, filterKeys = [], options = {}) {
  const filtersParam = urlParams.get('filters')
  if (filtersParam) {
    try {
      const decoded = decodeURIComponent(filtersParam)
      const parsed = JSON.parse(decoded)
      if (parsed && typeof parsed === 'object') return parsed
    } catch (e) { /* ignore */ }
  }
  return flatParamsToFilters(urlParams, filterKeys, options)
}

/**
 * Build URL query object for router.get: flat filter params + reserved params.
 * Removes 'filters' and any previous flat filter keys, then adds current filter params.
 *
 * @param {URLSearchParams} urlParams - Current URL params (for category, sort, etc.)
 * @param {Record<string, { operator?: string, value?: unknown }>} filters
 * @param {string[]} filterKeys
 * @param {{ preserveQueryKeys?: string[] }} [options] - Query keys to keep when stripping SPECIAL_FILTER_KEYS (e.g. Collections `collection` is navigation, not metadata)
 * @returns {Record<string, string | string[]>} All query params (values may be string[] for multi-value filters)
 */
export function buildUrlParamsWithFlatFilters(urlParams, filters, filterKeys = [], options = {}) {
  const preserve = new Set(options.preserveQueryKeys || [])
  const obj = Object.fromEntries(urlParams.entries())
  delete obj.filters
  const keysToRemove = new Set([...filterKeys, ...SPECIAL_FILTER_KEYS, 'dominant_hue_group'])
  keysToRemove.forEach((k) => {
    if (preserve.has(k)) return
    delete obj[k]
  })
  Object.keys(obj).forEach((k) => {
    const m = k.match(/^(.+)\[\d*\]$/)
    if (m && keysToRemove.has(m[1]) && !preserve.has(m[1])) delete obj[k]
  })
  const flat = filtersToFlatParams(filters, filterKeys)
  return { ...obj, ...flat }
}

/**
 * Remove param keys matching `base` or `base[...]` (PHP-style arrays).
 * @param {URLSearchParams} params
 * @param {string} base
 */
export function deleteParamAndArrayVariants(params, base) {
  const toRemove = []
  for (const k of params.keys()) {
    if (k === base || k.startsWith(`${base}[`)) toRemove.push(k)
  }
  for (const k of toRemove) params.delete(k)
}

/**
 * Remove one occurrence of a multi-value query key (e.g. tags=x) while preserving other values.
 * @param {string} searchString
 * @param {string} key
 * @param {string} valueToRemove
 * @returns {URLSearchParams}
 */
export function removeOneMultiValueParam(searchString, key, valueToRemove) {
  const raw = searchString.startsWith('?') ? searchString.slice(1) : searchString
  const next = new URLSearchParams(raw)
  const want = String(valueToRemove)
  const all = next.getAll(key)
  if (all.length === 0) return next
  deleteParamAndArrayVariants(next, key)
  for (const v of all) {
    if (String(v) !== want) {
      next.append(key, v)
    }
  }
  return next
}

/**
 * Strip one or more query keys (including PHP-style array variants).
 * @param {string} searchString
 * @param {string[]} keys
 * @returns {URLSearchParams}
 */
export function stripUrlParams(searchString, keys) {
  const raw = searchString.startsWith('?') ? searchString.slice(1) : searchString
  const next = new URLSearchParams(raw)
  for (const key of keys) {
    deleteParamAndArrayVariants(next, key)
  }
  return next
}

/**
 * Strip search + filter-related query params; keep category/collection/sort/navigation keys.
 *
 * @param {string} searchString - `window.location.search` (with or without leading `?`)
 * @param {{ filterKeys?: string[], collectionsView?: boolean }} options
 * @returns {URLSearchParams}
 */
export function clearToolbarFilterParams(searchString, { filterKeys = [], collectionsView = false } = {}) {
  const raw = searchString.startsWith('?') ? searchString.slice(1) : searchString
  const next = new URLSearchParams(raw)

  const alwaysClear = [
    'q',
    'page',
    'lifecycle',
    'uploaded_by',
    'file_type',
    'content_type',
    'compliance_filter',
    'filters',
    'asset',
    'edit_metadata',
  ]
  for (const p of alwaysClear) {
    deleteParamAndArrayVariants(next, p)
  }
  for (const fk of filterKeys) {
    deleteParamAndArrayVariants(next, fk)
  }
  // On Collections, `collection` is the selected collection id (navigation) — do not strip it.
  const multiFilterKeys = collectionsView ? ['tags', 'dominant_hue_group'] : ['tags', 'collection', 'dominant_hue_group']
  for (const k of multiFilterKeys) {
    deleteParamAndArrayVariants(next, k)
  }

  if (collectionsView) {
    deleteParamAndArrayVariants(next, 'category_id')
    next.set('collection_type', 'all')
  }

  return next
}

/**
 * Whether the URL has any search or filter state the toolbar "clear" action should reset.
 */
export function toolbarQueryHasClearableFilters(searchString, filterKeys = [], collectionsView = false) {
  const raw = searchString.startsWith('?') ? searchString.slice(1) : searchString
  const u = new URLSearchParams(raw)
  if ((u.get('q') || '').trim()) return true
  if ((u.get('asset') || '').trim()) return true
  if ((u.get('edit_metadata') || '').trim()) return true
  if (u.get('lifecycle')) return true
  if (u.get('uploaded_by')) return true
  const ft = u.get('file_type')
  if (ft && ft !== 'all') return true
  const contentType = u.get('content_type')
  if (contentType && contentType !== 'all') return true
  if (u.get('compliance_filter')) return true
  if (collectionsView) {
    if (u.get('category_id')) return true
    const ct = u.get('collection_type')
    if (ct && ct !== 'all') return true
  }
  for (const fk of filterKeys) {
    for (const k of u.keys()) {
      if (k === fk || k.startsWith(`${fk}[`)) return true
    }
  }
  const multiCheckKeys = collectionsView ? ['tags', 'dominant_hue_group'] : ['tags', 'collection', 'dominant_hue_group']
  for (const k of multiCheckKeys) {
    for (const key of u.keys()) {
      if (key === k || key.startsWith(`${k}[`)) return true
    }
  }
  return false
}
