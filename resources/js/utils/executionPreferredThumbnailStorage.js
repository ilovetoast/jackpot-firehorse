/**
 * Per-asset preferred execution preview tier (drawer + "Standard" grid mode).
 * Stored in localStorage — brand/user device local only.
 */

const PREF_KEY = 'jackpot_execution_preferred_thumbnail_tier_by_asset'

/** @typedef {'original' | 'enhanced' | 'presentation'} ExecutionPreviewTier */

/**
 * @param {unknown} id
 * @returns {ExecutionPreviewTier | null}
 */
export function getPreferredExecutionThumbnailTier(id) {
    if (id == null || id === '') {
        return null
    }
    try {
        const raw = localStorage.getItem(PREF_KEY)
        if (!raw) {
            return null
        }
        const map = JSON.parse(raw)
        if (!map || typeof map !== 'object') {
            return null
        }
        const v = map[String(id)]
        if (v === 'original' || v === 'enhanced' || v === 'presentation') {
            return v
        }
        return null
    } catch {
        return null
    }
}

/**
 * @param {unknown} id
 * @param {ExecutionPreviewTier} tier
 */
export function setPreferredExecutionThumbnailTier(id, tier) {
    if (id == null || id === '') {
        return
    }
    if (tier !== 'original' && tier !== 'enhanced' && tier !== 'presentation') {
        return
    }
    try {
        const raw = localStorage.getItem(PREF_KEY)
        const map = raw ? JSON.parse(raw) : {}
        const next = map && typeof map === 'object' ? { ...map } : {}
        next[String(id)] = tier
        localStorage.setItem(PREF_KEY, JSON.stringify(next))
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
