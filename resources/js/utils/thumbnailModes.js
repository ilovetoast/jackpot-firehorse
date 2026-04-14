/**
 * Thumbnail pipeline modes (original / preferred “clean” / enhanced) — metadata helpers for the drawer UI.
 * Delivery URLs: {@see getThumbnailUrl} in ./thumbnailUrlResolve.js (re-exported from thumbnailUtils.js; `thumbnail_mode_urls` from the API).
 */

/**
 * @param {Object|null|undefined} asset
 * @returns {Record<string, unknown>}
 */
export function getThumbnailsByMode(asset) {
    const m = asset?.metadata
    if (!m || typeof m !== 'object') {
        return {}
    }
    if (m.thumbnails_by_mode && typeof m.thumbnails_by_mode === 'object') {
        return m.thumbnails_by_mode
    }
    if (m.thumbnails && typeof m.thumbnails === 'object') {
        return m.thumbnails
    }
    return {}
}

/**
 * @param {Object|null|undefined} asset
 * @returns {Record<string, string>}
 */
export function getThumbnailModesStatus(asset) {
    const top = asset?.thumbnail_modes_status
    if (top && typeof top === 'object') {
        return /** @type {Record<string, string>} */ (top)
    }
    const nested = asset?.metadata?.thumbnail_modes_status
    return nested && typeof nested === 'object' ? /** @type {Record<string, string>} */ (nested) : {}
}

/**
 * @param {Object|null|undefined} asset
 * @param {string} mode
 */
export function modeHasRenderableThumbnails(asset, mode) {
    const urls = asset?.thumbnail_mode_urls?.[mode]
    return !!(urls && typeof urls === 'object' && Object.keys(urls).length > 0)
}

/**
 * @param {Object|null|undefined} asset
 * @param {string} mode
 * @returns {Record<string, unknown>}
 */
export function getThumbnailModesModeMeta(asset, mode) {
    const top = asset?.thumbnail_modes_meta?.[mode]
    if (top && typeof top === 'object') {
        return /** @type {Record<string, unknown>} */ (top)
    }
    const nested = asset?.metadata?.thumbnail_modes_meta?.[mode]
    return nested && typeof nested === 'object' ? /** @type {Record<string, unknown>} */ (nested) : {}
}

/** Backend: ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL */
export const ENHANCED_SKIP_REASON_TOO_SMALL = 'too_small'

/**
 * Preferred “clean” crop changed so little it’s not worth offering as a distinct preview mode.
 *
 * @param {Object|null|undefined} asset
 */
function preferredCleanPreviewIsLowSignal(asset) {
    const p = getThumbnailModesModeMeta(asset, 'preferred')
    const tr = p.trim_ratio
    const ed = p.edge_density
    if (typeof tr !== 'number' || typeof ed !== 'number') {
        return false
    }
    return tr < 0.05 && ed < 0.4
}

/**
 * When enhanced pipeline is `complete`, true if the stored output still matches source + template
 * (aligns with PHP `EnhancedPreviewFingerprint::isCompleteOutputStillFresh`).
 *
 * @param {Object|null|undefined} asset
 */
export function isCompleteEnhancedOutputStillFresh(asset) {
    const st = String(getThumbnailModesStatus(asset).enhanced || '').toLowerCase()
    if (st !== 'complete') {
        return false
    }
    const m = getThumbnailModesModeMeta(asset, 'enhanced')
    return m.output_fresh !== false
}

/**
 * True when enhanced is complete but output is not fresh (`output_fresh === false`).
 *
 * @param {Object|null|undefined} asset
 */
export function isEnhancedOutputStale(asset) {
    const st = String(getThumbnailModesStatus(asset).enhanced || '').toLowerCase()
    if (st !== 'complete') {
        return false
    }
    return !isCompleteEnhancedOutputStillFresh(asset)
}

/**
 * Keep prior presigned URLs when {@see thumbnail_modes_meta}.{mode}.cache_key} is unchanged
 * (avoids img reload / flicker on drawer poll when only signatures rotated).
 *
 * @param {Record<string, Record<string, string>>|null|undefined} prevUrls
 * @param {Record<string, Record<string, string>>|null|undefined} nextUrls
 * @param {Record<string, { cache_key?: string }>|null|undefined} prevMeta
 * @param {Record<string, { cache_key?: string }>|null|undefined} nextMeta
 * @returns {Record<string, Record<string, string>>|null|undefined}
 */
export function mergeThumbnailModeUrlsPreserveCache(prevUrls, nextUrls, prevMeta, nextMeta) {
    if (!nextUrls || typeof nextUrls !== 'object') {
        return prevUrls
    }
    if (!prevUrls || typeof prevUrls !== 'object') {
        return nextUrls
    }
    const modes = ['original', 'preferred', 'enhanced', 'presentation']
    const out = { ...nextUrls }
    for (const mode of modes) {
        const pk = prevMeta?.[mode]?.cache_key
        const nk = nextMeta?.[mode]?.cache_key
        if (typeof pk === 'string' && typeof nk === 'string' && pk === nk && prevUrls[mode]) {
            out[mode] = prevUrls[mode]
        }
    }
    return out
}

const DRAWER_THUMBNAIL_MODES = ['original', 'preferred', 'enhanced', 'presentation']

function modeUrlBucketNonempty(bucket) {
    return bucket && typeof bucket === 'object' && Object.keys(bucket).length > 0
}

/**
 * When the grid sends a partial `thumbnail_mode_urls` (e.g. only `original`), keep mode buckets
 * the drawer already learned from polling so Enhanced / Presentation tiles do not go blank.
 *
 * @param {Record<string, Record<string, string>>|null|undefined} nextUrls - prop from grid
 * @param {Record<string, Record<string, string>>|null|undefined} prevUrls - previous drawer state
 * @returns {Record<string, Record<string, string>>|null|undefined}
 */
export function mergeThumbnailModeUrlsDrawerSync(nextUrls, prevUrls) {
    const out = {}
    for (const mode of DRAWER_THUMBNAIL_MODES) {
        const n = nextUrls?.[mode]
        const p = prevUrls?.[mode]
        if (modeUrlBucketNonempty(n)) {
            out[mode] = n
        } else if (modeUrlBucketNonempty(p)) {
            out[mode] = p
        }
    }
    if (Object.keys(out).length > 0) {
        return out
    }
    if (nextUrls && typeof nextUrls === 'object' && Object.keys(nextUrls).length > 0) {
        return nextUrls
    }
    if (prevUrls && typeof prevUrls === 'object' && Object.keys(prevUrls).length > 0) {
        return prevUrls
    }
    return undefined
}

/**
 * Whether the drawer should offer “Clean (Preferred)” as a preview option.
 * Failed: hidden; not generated: hidden; processing / complete / has URLs: show.
 *
 * @param {Object|null|undefined} asset
 */
export function shouldShowPreferredPreviewOption(asset) {
    const st = String(getThumbnailModesStatus(asset).preferred || '').toLowerCase()
    if (st === 'failed') {
        return false
    }
    let show = false
    if (st === 'processing' || st === 'complete') {
        show = true
    } else if (modeHasRenderableThumbnails(asset, 'preferred')) {
        show = true
    }
    if (!show) {
        return false
    }
    if (preferredCleanPreviewIsLowSignal(asset)) {
        return false
    }
    return true
}

/**
 * @param {Object|null|undefined} asset
 */
export function shouldShowEnhancedPreviewOption(asset) {
    return modeHasRenderableThumbnails(asset, 'enhanced')
}

/**
 * Deliverables drawer: show Enhanced radio when URLs exist or the enhanced pipeline has a known state.
 *
 * @param {Object|null|undefined} asset
 */
export function shouldShowEnhancedPreviewRadio(asset) {
    if (shouldShowEnhancedPreviewOption(asset)) {
        return true
    }
    const st = String(getThumbnailModesStatus(asset).enhanced || '').toLowerCase()
    return st === 'processing' || st === 'complete' || st === 'failed' || st === 'skipped'
}

/**
 * @param {Object|null|undefined} asset
 */
export function shouldShowPresentationPreviewOption(asset) {
    return modeHasRenderableThumbnails(asset, 'presentation')
}

/**
 * Drawer: show Presentation radio when URLs exist or pipeline has a known state.
 *
 * @param {Object|null|undefined} asset
 */
export function shouldShowPresentationPreviewRadio(asset) {
    if (shouldShowPresentationPreviewOption(asset)) {
        return true
    }
    const st = String(getThumbnailModesStatus(asset).presentation || '').toLowerCase()
    return st === 'processing' || st === 'complete' || st === 'failed' || st === 'skipped'
}

/**
 * @param {unknown} iso
 * @returns {string|null}
 */
export function formatIsoDateTimeLocal(iso) {
    if (iso == null || iso === '') {
        return null
    }
    const d = new Date(typeof iso === 'string' ? iso : String(iso))
    if (Number.isNaN(d.getTime())) {
        return null
    }
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
}

/**
 * Label for async preview jobs (enhanced / presentation) using `last_attempt_at`.
 *
 * @param {string} status - thumbnail_modes_status for that mode
 * @param {unknown} iso - last_attempt_at
 * @returns {string|null}
 */
export function formatThumbnailPipelineAttemptLabel(status, iso) {
    const t = formatIsoDateTimeLocal(iso)
    if (!t) {
        return null
    }
    const s = String(status || '').toLowerCase()
    if (s === 'processing') {
        return `Started ${t}`
    }
    if (s === 'complete') {
        return `Last generated ${t}`
    }
    return `Last attempt ${t}`
}

/**
 * Compact two-line meta for drawer tiles: short label + date/time on second line.
 *
 * @param {string} status
 * @param {unknown} iso
 * @returns {{ head: string, time: string }|null}
 */
export function formatThumbnailPipelineAttemptParts(status, iso) {
    const time = formatIsoDateTimeLocal(iso)
    if (!time) {
        return null
    }
    const s = String(status || '').toLowerCase()
    const head = s === 'processing' ? 'Started' : s === 'complete' ? 'Generated' : 'Attempt'
    return { head, time }
}
