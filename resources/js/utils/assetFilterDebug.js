/**
 * Single debug state object for asset filter troubleshooting.
 *
 * Use in console: window.__assetFilterDebug
 *
 * Updated by filter visibility, scope, primary filters, toolbar, and processing tray.
 * Enables one-place inspection for enterprise DAM filter behavior and new field types.
 */

const DEBUG_KEY = '__assetFilterDebug'

const emptyState = () => ({
  filters: {},
  url: { search: '', flatParams: {} },
  visibility: { byField: {}, visiblePrimary: [], visibleSecondary: [], hiddenCount: 0 },
  scope: { category_id: null, asset_type: null, compatible: {} },
  pendingAssets: { categoryId: null, count: null, loading: false },
  processingTray: { activeJobs: 0, autoDismiss: false },
  schema: { filterKeys: [], primaryKeys: [] },
  lastUpdated: null,
})

function getState() {
  if (typeof window === 'undefined') return null
  if (!window[DEBUG_KEY]) window[DEBUG_KEY] = emptyState()
  return window[DEBUG_KEY]
}

/**
 * Merge partial state into the global debug object and set lastUpdated.
 * Call from filterVisibilityRules, filterScopeRules, AssetGridMetadataPrimaryFilters,
 * AssetGridToolbar, AssetProcessingTray instead of console.log.
 *
 * @param {Partial<typeof emptyState>} partial
 */
export function updateFilterDebug(partial) {
  const state = getState()
  if (!state) return
  if (partial && typeof partial === 'object') {
    if (partial.filters) state.filters = partial.filters
    if (partial.url) state.url = { ...state.url, ...partial.url }
    if (partial.visibility) state.visibility = { ...state.visibility, ...partial.visibility }
    if (partial.scope) state.scope = { ...state.scope, ...partial.scope }
    if (partial.pendingAssets) state.pendingAssets = { ...state.pendingAssets, ...partial.pendingAssets }
    if (partial.processingTray) state.processingTray = { ...state.processingTray, ...partial.processingTray }
    if (partial.schema) state.schema = { ...state.schema, ...partial.schema }
  }
  state.lastUpdated = new Date().toISOString()
}

/**
 * Reset debug state (e.g. for fresh load).
 */
export function resetFilterDebug() {
  if (typeof window !== 'undefined' && window[DEBUG_KEY]) {
    window[DEBUG_KEY] = emptyState()
  }
}

export { getState }
