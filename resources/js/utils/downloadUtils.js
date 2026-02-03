/**
 * Download list utilities for patch-based polling.
 * Polling must only patch mutable fields; never replace the full downloads array.
 */

/** Mutable fields that polling is allowed to update (state, progress, etc.) */
const MUTABLE_POLL_FIELDS = [
  'state',
  'zip_total_chunks',
  'zip_chunk_index',
  'zip_progress_percentage',
  'is_zip_stalled',
  'is_possibly_stuck',
  'zip_time_estimate',
  'estimated_bytes',
  'zip_size_bytes',
  'public_url',
]

/**
 * Build a map of download id -> download from an array.
 * @param {Array} downloads
 * @returns {Record<string, object>}
 */
export function keyByDownloads(downloads) {
  if (!Array.isArray(downloads)) return {}
  const map = {}
  for (const d of downloads) {
    if (d && d.id) map[d.id] = d
  }
  return map
}

/**
 * Merge polled mutable fields into a previous download object.
 * Only MUTABLE_POLL_FIELDS are overwritten; all other fields stay from prev.
 * @param {Object} prev - Previous download state
 * @param {Object} patch - Poll response for this download (id + mutable fields)
 * @returns {Object} - Merged download
 */
export function mergeDownloadPatch(prev, patch) {
  if (!prev) return patch
  if (!patch || !patch.id || prev.id !== patch.id) return prev
  const next = { ...prev }
  for (const key of MUTABLE_POLL_FIELDS) {
    if (patch.hasOwnProperty(key)) next[key] = patch[key]
  }
  return next
}

/**
 * Dev-only: warn when polling would replace root state (full array or page-level state).
 * Call this if you detect a forbidden setState pattern.
 * @param {string} context - e.g. 'downloads array', 'page-level state'
 */
export function warnIfReplacingRootState(context) {
  if (typeof process !== 'undefined' && process.env?.NODE_ENV === 'development') {
    console.warn('[Polling] Root state replaced — this will reset UI', { context })
  }
}
