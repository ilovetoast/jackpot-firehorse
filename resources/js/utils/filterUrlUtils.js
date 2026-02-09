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
])

/**
 * @param {Record<string, { operator?: string, value?: unknown }>} filters
 * @param {string[]} [filterKeys] - If provided, only include these keys (e.g. from filterable_schema)
 * @returns {Record<string, string>} Flat params for URL (key → string value)
 */
export function filtersToFlatParams(filters, filterKeys = null) {
  if (!filters || typeof filters !== 'object') return {}
  const keys = filterKeys || Object.keys(filters)
  const out = {}
  for (const key of keys) {
    if (RESERVED_PARAMS.has(key)) continue
    const def = filters[key]
    if (!def || (def.value === undefined && def.value === null)) continue
    const v = def.value
    if (Array.isArray(v)) {
      if (v.length > 0) out[key] = String(v[0])
    } else if (v !== '' && v !== null && v !== undefined) {
      out[key] = String(v)
    }
  }
  return out
}

/**
 * @param {URLSearchParams|Record<string, string>} params - URL params or object
 * @param {string[]} filterKeys - Allowed filter keys (e.g. from filterable_schema); only these are read
 * @returns {Record<string, { operator: string, value: string }>} filters object
 */
export function flatParamsToFilters(params, filterKeys = []) {
  if (!filterKeys || filterKeys.length === 0) return {}
  const entries = params instanceof URLSearchParams
    ? Array.from(params.entries())
    : Object.entries(params || {})
  const keySet = new Set(filterKeys)
  const out = {}
  for (const [key, value] of entries) {
    if (RESERVED_PARAMS.has(key) || !keySet.has(key)) continue
    if (value === '' || value === null || value === undefined) continue
    out[key] = { operator: 'equals', value: value }
  }
  return out
}

/**
 * Parse filters from URL: prefer flat params, fallback to legacy 'filters' JSON.
 *
 * @param {URLSearchParams} urlParams
 * @param {string[]} filterKeys - From filterable_schema (field_key)
 * @returns {Record<string, { operator: string, value: unknown }>}
 */
export function parseFiltersFromUrl(urlParams, filterKeys = []) {
  const filtersParam = urlParams.get('filters')
  if (filtersParam) {
    try {
      const decoded = decodeURIComponent(filtersParam)
      const parsed = JSON.parse(decoded)
      if (parsed && typeof parsed === 'object') return parsed
    } catch (e) { /* ignore */ }
  }
  return flatParamsToFilters(urlParams, filterKeys)
}

/**
 * Build URL query object for router.get: flat filter params + reserved params.
 * Removes 'filters' and any previous flat filter keys, then adds current filter params.
 *
 * @param {URLSearchParams} urlParams - Current URL params (for category, sort, etc.)
 * @param {Record<string, { operator?: string, value?: unknown }>} filters
 * @param {string[]} filterKeys
 * @returns {Record<string, string>} All query params as object
 */
export function buildUrlParamsWithFlatFilters(urlParams, filters, filterKeys = []) {
  const obj = Object.fromEntries(urlParams.entries())
  delete obj.filters
  filterKeys.forEach(k => delete obj[k])
  const flat = filtersToFlatParams(filters, filterKeys)
  return { ...obj, ...flat }
}
