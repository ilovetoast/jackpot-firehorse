/**
 * Deliverables / execution grid: thumbnail URL for Standard | Studio | Presentation (CSS) | AI modes
 * with per-asset graceful fallbacks.
 */
import { getPreferredExecutionThumbnailTier } from './executionPreferredThumbnailStorage.js'
import { getThumbnailUrl, getThumbnailUrlModeOnly } from './thumbnailUrlResolve.js'

/** @typedef {'standard' | 'enhanced' | 'presentation' | 'ai' | 'original'} ExecutionThumbnailViewMode */

const VALID_PRESENTATION_PRESETS = new Set(['neutral_studio', 'desk_surface', 'wall_pin'])

/**
 * Presentation CSS preset saved for this asset (null if none / invalid).
 *
 * @param {Object|null|undefined} asset
 * @returns {'neutral_studio'|'desk_surface'|'wall_pin'|null}
 */
export function getExecutionPresentationPresetKey(asset) {
    if (!asset) {
        return null
    }
    const block =
        asset?.metadata?.thumbnail_modes_meta?.presentation_css ?? asset?.thumbnail_modes_meta?.presentation_css
    if (!block || typeof block !== 'object') {
        return null
    }
    const raw = block.preset
    return VALID_PRESENTATION_PRESETS.has(raw) ? raw : null
}

/**
 * Image URL inside CSS presentation presets: Studio → Source (no preferred tier).
 *
 * @param {Object|null|undefined} asset
 * @param {'thumb'|'medium'|'large'} style
 * @returns {string|null}
 */
export function getExecutionPresentationBaseImageUrl(asset, style = 'medium') {
    if (!asset) {
        return null
    }
    const pick = (mode) =>
        getThumbnailUrlModeOnly(asset, style, mode) ||
        getThumbnailUrlModeOnly(asset, 'medium', mode) ||
        getThumbnailUrlModeOnly(asset, 'large', mode) ||
        getThumbnailUrlModeOnly(asset, 'thumb', mode)
    const studio = pick('enhanced')
    if (studio) {
        return studio
    }
    return getThumbnailUrl(asset, style, 'original')
}

/**
 * Studio thumbnail if present, else source (original).
 *
 * @param {Object|null|undefined} asset
 * @param {'thumb'|'medium'|'large'} style
 * @returns {string|null}
 */
export function getExecutionStudioThenSourceUrl(asset, style = 'medium') {
    if (!asset) {
        return null
    }
    const e =
        getThumbnailUrlModeOnly(asset, style, 'enhanced') ||
        getThumbnailUrlModeOnly(asset, 'medium', 'enhanced') ||
        getThumbnailUrlModeOnly(asset, 'large', 'enhanced') ||
        getThumbnailUrlModeOnly(asset, 'thumb', 'enhanced')
    if (e) {
        return e
    }
    return getThumbnailUrl(asset, style, 'original')
}

/**
 * @param {Object|null|undefined} asset
 * @param {'thumb'|'medium'|'large'} style
 * @returns {{ imageUrl: string|null, usePresentationCss: boolean, presentationPreset: string|null }}
 */
function resolvePresentationCell(asset, style) {
    const preset = getExecutionPresentationPresetKey(asset)
    const base = getExecutionPresentationBaseImageUrl(asset, style)
    if (preset && base) {
        return { imageUrl: base, usePresentationCss: true, presentationPreset: preset }
    }
    return {
        imageUrl: getExecutionStudioThenSourceUrl(asset, style),
        usePresentationCss: false,
        presentationPreset: null,
    }
}

/**
 * @param {Object|null|undefined} asset
 * @param {'thumb'|'medium'|'large'} style
 * @returns {{ imageUrl: string|null, usePresentationCss: boolean, presentationPreset: string|null }}
 */
function resolveAiCell(asset, style) {
    const pr =
        getThumbnailUrlModeOnly(asset, style, 'presentation') ||
        getThumbnailUrlModeOnly(asset, 'medium', 'presentation') ||
        getThumbnailUrlModeOnly(asset, 'large', 'presentation') ||
        getThumbnailUrlModeOnly(asset, 'thumb', 'presentation')
    if (pr) {
        return { imageUrl: pr, usePresentationCss: false, presentationPreset: null }
    }
    return {
        imageUrl: getExecutionStudioThenSourceUrl(asset, style),
        usePresentationCss: false,
        presentationPreset: null,
    }
}

/**
 * Resolved grid thumbnail: URL + whether to wrap in CSS presentation frame.
 *
 * @param {Object|null|undefined} asset
 * @param {ExecutionThumbnailViewMode | 'clean' | string} mode
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @returns {{ imageUrl: string|null, usePresentationCss: boolean, presentationPreset: string|null }}
 */
export function resolveExecutionGridThumbnail(asset, mode, style = 'medium') {
    const none = { imageUrl: null, usePresentationCss: false, presentationPreset: null }
    if (!asset) {
        return none
    }

    if (mode === 'standard') {
        const pref = getPreferredExecutionThumbnailTier(asset.id)
        if (pref === 'original' || pref === 'enhanced' || pref === 'presentation' || pref === 'ai') {
            if (pref === 'presentation') {
                return resolvePresentationCell(asset, style)
            }
            if (pref === 'ai') {
                return resolveAiCell(asset, style)
            }
            if (pref === 'enhanced') {
                return {
                    imageUrl: getExecutionStudioThenSourceUrl(asset, style),
                    usePresentationCss: false,
                    presentationPreset: null,
                }
            }
            return {
                imageUrl: getThumbnailUrl(asset, style, 'original'),
                usePresentationCss: false,
                presentationPreset: null,
            }
        }
        return {
            imageUrl: getThumbnailUrl(asset, style, 'original'),
            usePresentationCss: false,
            presentationPreset: null,
        }
    }

    if (mode === 'original') {
        return {
            imageUrl: getThumbnailUrl(asset, style, 'original'),
            usePresentationCss: false,
            presentationPreset: null,
        }
    }

    if (mode === 'enhanced') {
        return {
            imageUrl: getExecutionStudioThenSourceUrl(asset, style),
            usePresentationCss: false,
            presentationPreset: null,
        }
    }

    if (mode === 'presentation') {
        return resolvePresentationCell(asset, style)
    }

    if (mode === 'ai') {
        return resolveAiCell(asset, style)
    }

    if (mode === 'clean') {
        const prefUrl = getThumbnailUrlModeOnly(asset, style, 'preferred')
        if (prefUrl) {
            return { imageUrl: prefUrl, usePresentationCss: false, presentationPreset: null }
        }
        return {
            imageUrl: getThumbnailUrl(asset, style, 'original'),
            usePresentationCss: false,
            presentationPreset: null,
        }
    }

    const e = getThumbnailUrlModeOnly(asset, style, 'enhanced')
    if (e) {
        return { imageUrl: e, usePresentationCss: false, presentationPreset: null }
    }
    const p = getThumbnailUrlModeOnly(asset, style, 'preferred')
    if (p) {
        return { imageUrl: p, usePresentationCss: false, presentationPreset: null }
    }
    return {
        imageUrl: getThumbnailUrl(asset, style, 'original'),
        usePresentationCss: false,
        presentationPreset: null,
    }
}

/**
 * Primary URL for grid cell (non-hover). Per-asset fallbacks per mode.
 *
 * @param {Object|null|undefined} asset
 * @param {ExecutionThumbnailViewMode} mode
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @returns {string|null}
 */
export function getExecutionGridDisplayUrl(asset, mode, style = 'medium') {
    return resolveExecutionGridThumbnail(asset, mode, style).imageUrl
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
    const display = resolveExecutionGridThumbnail(asset, mode, style).imageUrl
    const original = getThumbnailUrl(asset, style, 'original')
    if (!display || !original || display === original) {
        return null
    }
    return original
}
