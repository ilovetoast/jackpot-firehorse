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

/** Keys that must be included in URL for load_more / infinite scroll to respect filters (backend applies these even if not in schema). */
const SPECIAL_FILTER_KEYS = ['tags', 'collection']

/** Keys that support multiple values in the URL (repeated param: tags=hero&tags=campaign). Backend accepts array for these. */
const MULTI_VALUE_FILTER_KEYS = new Set(['tags', 'collection'])

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
      const nonEmpty = v.filter(x => x !== '' && x !== null && x !== undefined).map(String)
      if (nonEmpty.length > 0) {
        // Multi-value: keep all so URL gets e.g. tags=hero&tags=campaign (or single value as array of one)
        out[key] = MULTI_VALUE_FILTER_KEYS.has(key) ? nonEmpty : [nonEmpty[0]]
      }
    } else if (v !== '' && v !== null && v !== undefined) {
      out[key] = String(v)
    }
  }
  return out
}

/**
 * @param {URLSearchParams|Record<string, string>} params - URL params or object
 * @param {string[]} filterKeys - Allowed filter keys (e.g. from filterable_schema); only these are read
 * @returns {Record<string, { operator: string, value: string | string[] }>} filters object (multi-value keys get value as string[])
 */
export function flatParamsToFilters(params, filterKeys = []) {
  const keySet = new Set([...(filterKeys || []), ...SPECIAL_FILTER_KEYS])
  if (keySet.size === 0) return {}
  const entries = params instanceof URLSearchParams
    ? Array.from(params.entries())
    : Object.entries(params || {})
  const out = {}
  for (const [key, value] of entries) {
    if (RESERVED_PARAMS.has(key) || !keySet.has(key)) continue
    if (value === '' || value === null || value === undefined) continue
    const isMulti = MULTI_VALUE_FILTER_KEYS.has(key)
    if (isMulti && key in out) {
      const existing = out[key].value
      out[key] = { operator: 'equals', value: Array.isArray(existing) ? [...existing, value] : [existing, value] }
    } else {
      out[key] = { operator: 'equals', value: value }
    }
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
 * @returns {Record<string, string | string[]>} All query params (values may be string[] for multi-value filters)
 */
export function buildUrlParamsWithFlatFilters(urlParams, filters, filterKeys = []) {
  const obj = Object.fromEntries(urlParams.entries())
  delete obj.filters
  filterKeys.forEach(k => delete obj[k])
  const flat = filtersToFlatParams(filters, filterKeys)
  return { ...obj, ...flat }
}
