/**
 * Per-asset preferred execution preview tier (drawer + "Standard" grid mode).
 * Stored in localStorage — brand/user device local only.
 *
 * v2 key: supports `presentation` = CSS preset view and `ai` = AI raster.
 * Legacy map: `presentation` meant AI raster — migrated on read to `ai`.
 */

const PREF_KEY_V2 = 'jackpot_execution_preferred_thumbnail_tier_by_asset_v2'
const PREF_KEY_LEGACY = 'jackpot_execution_preferred_thumbnail_tier_by_asset'

/** @typedef {'original' | 'enhanced' | 'presentation' | 'ai'} ExecutionPreviewTier */

function readMap(key) {
    try {
        const raw = localStorage.getItem(key)
        if (!raw) {
            return null
        }
        const map = JSON.parse(raw)
        return map && typeof map === 'object' ? map : null
    } catch {
        return null
    }
}

/**
 * @param {unknown} id
 * @returns {ExecutionPreviewTier | null}
 */
export function getPreferredExecutionThumbnailTier(id) {
    if (id == null || id === '') {
        return null
    }
    const mapV2 = readMap(PREF_KEY_V2)
    if (mapV2 && Object.prototype.hasOwnProperty.call(mapV2, String(id))) {
        const v = mapV2[String(id)]
        if (v === 'original' || v === 'enhanced' || v === 'presentation' || v === 'ai') {
            return v
        }
        return null
    }
    const legacy = readMap(PREF_KEY_LEGACY)
    if (!legacy || !Object.prototype.hasOwnProperty.call(legacy, String(id))) {
        return null
    }
    const v = legacy[String(id)]
    if (v === 'original' || v === 'enhanced') {
        return v
    }
    if (v === 'presentation') {
        return 'ai'
    }
    return null
}

/**
 * @param {unknown} id
 * @param {ExecutionPreviewTier} tier
 */
export function setPreferredExecutionThumbnailTier(id, tier) {
    if (id == null || id === '') {
        return
    }
    if (tier !== 'original' && tier !== 'enhanced' && tier !== 'presentation' && tier !== 'ai') {
        return
    }
    try {
        const raw = localStorage.getItem(PREF_KEY_V2)
        const map = raw ? JSON.parse(raw) : {}
        const next = map && typeof map === 'object' ? { ...map } : {}
        next[String(id)] = tier
        localStorage.setItem(PREF_KEY_V2, JSON.stringify(next))
        if (typeof window !== 'undefined') {
            window.dispatchEvent(
                new CustomEvent('jackpot_preferred_thumbnail_tier_changed', {
                    detail: { assetId: String(id) },
                }),
            )
        }
    } catch {
        /* ignore quota / private mode */
    }
}
