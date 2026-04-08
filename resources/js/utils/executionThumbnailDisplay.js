/**
 * Deliverables / execution grid: thumbnail URL for Standard | Enhanced | Presentation modes (with fallbacks).
 */
import { getPreferredExecutionThumbnailTier } from './executionPreferredThumbnailStorage.js'
import { getThumbnailUrl, getThumbnailUrlModeOnly } from './thumbnailUrlResolve.js'

/** @typedef {'standard' | 'enhanced' | 'presentation' | 'original'} ExecutionThumbnailViewMode */

/**
 * @param {Object|null|undefined} asset
 * @param {'original' | 'enhanced' | 'presentation'} tier
 * @param {'thumb'|'medium'|'large'} style
 * @returns {string|null}
 */
function executionGridUrlForTier(asset, tier, style) {
    if (!asset) {
        return null
    }
    if (tier === 'original') {
        return getThumbnailUrl(asset, style, 'original')
    }
    if (tier === 'enhanced') {
        const e = getThumbnailUrlModeOnly(asset, style, 'enhanced')
        if (e) {
            return e
        }
        const p = getThumbnailUrlModeOnly(asset, style, 'preferred')
        if (p) {
            return p
        }
        return getThumbnailUrl(asset, style, 'original')
    }
    if (tier === 'presentation') {
        const pr = getThumbnailUrlModeOnly(asset, style, 'presentation')
        if (pr) {
            return pr
        }
        const e = getThumbnailUrlModeOnly(asset, style, 'enhanced')
        if (e) {
            return e
        }
        const p = getThumbnailUrlModeOnly(asset, style, 'preferred')
        if (p) {
            return p
        }
        return getThumbnailUrl(asset, style, 'original')
    }
    return getThumbnailUrl(asset, style, 'original')
}

/**
 * Primary URL for grid cell (non-hover). Cascades down when a tier is missing.
 *
 * - **standard** — per-asset preferred tier from the drawer (localStorage) if set; else source (original).
 * - **enhanced** — whole grid uses enhanced cascade for every asset (ignores per-asset pref).
 * - **presentation** — whole grid uses presentation cascade for every asset (ignores per-asset pref).
 * - **original** — source / original thumbnail only (legacy; prefer `standard` in UI).
 *
 * @param {Object|null|undefined} asset
 * @param {ExecutionThumbnailViewMode} mode
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @returns {string|null}
 */
export function getExecutionGridDisplayUrl(asset, mode, style = 'medium') {
    if (!asset) {
        return null
    }

    if (mode === 'standard') {
        const pref = getPreferredExecutionThumbnailTier(asset.id)
        if (pref === 'original' || pref === 'enhanced' || pref === 'presentation') {
            return executionGridUrlForTier(asset, pref, style)
        }

        return executionGridUrlForTier(asset, 'original', style)
    }
    if (mode === 'original') {
        return getThumbnailUrl(asset, style, 'original')
    }
    if (mode === 'enhanced') {
        return executionGridUrlForTier(asset, 'enhanced', style)
    }
    if (mode === 'presentation') {
        return executionGridUrlForTier(asset, 'presentation', style)
    }
    /* Legacy */
    if (mode === 'clean') {
        const prefUrl = getThumbnailUrlModeOnly(asset, style, 'preferred')
        if (prefUrl) {
            return prefUrl
        }
        return getThumbnailUrl(asset, style, 'original')
    }
    const e = getThumbnailUrlModeOnly(asset, style, 'enhanced')
    if (e) {
        return e
    }
    const p = getThumbnailUrlModeOnly(asset, style, 'preferred')
    if (p) {
        return p
    }
    return getThumbnailUrl(asset, style, 'original')
}

/**
 * Hover partner: raw original when the cell is showing something else (quick before/after).
 *
 * @param {Object|null|undefined} asset
 * @param {ExecutionThumbnailViewMode} mode
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @returns {string|null}
 */
export function getExecutionGridHoverCrossfadeUrl(asset, mode, style = 'medium') {
    if (!asset) {
        return null
    }
    const display = getExecutionGridDisplayUrl(asset, mode, style)
    const original = getThumbnailUrl(asset, style, 'original')
    if (!display || !original || display === original) {
        return null
    }
    return original
}
