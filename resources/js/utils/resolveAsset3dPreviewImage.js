/**
 * Central helpers for Phase 5A/5B: registry `model_*` poster thumbnails (`preview_3d_poster_url`);
 * GLB-only realtime viewer gating (`preview_3d_viewer_url`, `model_glb`, DAM_3D).
 */

import { getRegisteredTypesForHelp } from './damFileTypes.js'

function extensionOf(asset) {
    return (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

/**
 * True when the server marked the 3D raster poster as a pipeline stub (not a Blender render).
 * Uses API `preview_3d_poster_is_stub` first, then `metadata.preview_3d.debug.poster_stub` for older payloads.
 *
 * @param {object|null|undefined} asset
 * @returns {boolean}
 */
export function isRegistryModel3dPosterStub(asset) {
    if (!asset) {
        return false
    }
    if (asset.preview_3d_poster_is_stub === true) {
        return true
    }
    const dbg = asset.metadata?.preview_3d?.debug
    if (dbg && typeof dbg === 'object' && dbg.poster_stub === true) {
        return true
    }
    return false
}

/**
 * @param {object|null|undefined} asset
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damOverride]
 * @returns {boolean}
 */
export function isRegistryModel3dAsset(asset, damOverride) {
    if (!asset) return false
    const ext = extensionOf(asset)
    if (!ext) return false
    const rows = Array.isArray(damOverride?.types_for_help)
        ? damOverride.types_for_help
        : getRegisteredTypesForHelp()
    for (const row of rows) {
        const key = String(row?.key || '')
        if (!key.startsWith('model_')) continue
        const exts = Array.isArray(row.extensions)
            ? row.extensions.map((e) => String(e).toLowerCase())
            : []
        if (exts.includes(ext)) {
            return true
        }
    }
    return false
}

/**
 * Signed delivery URL for the 3D poster when the asset is a registry `model_*` type.
 *
 * @param {object|null|undefined} asset
 * @param {Set<string>|null|undefined} failedUrlSet URLs that failed to load (same Set as {@link ./thumbnailRasterFailedCache.js} / ThumbnailPreview).
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damOverride]
 * @returns {string|null}
 */
export function getRegistryModel3dPosterDisplayUrl(asset, failedUrlSet, damOverride) {
    if (!isRegistryModel3dAsset(asset, damOverride)) {
        return null
    }
    if (isRegistryModel3dPosterStub(asset)) {
        return null
    }
    const u = asset?.preview_3d_poster_url
    if (typeof u !== 'string' || !u.trim()) {
        return null
    }
    const trimmed = u.trim()
    if (failedUrlSet?.has(trimmed)) {
        return null
    }
    return trimmed
}

/**
 * Registry row `model_glb` with `.glb` extension (Phase 5B — realtime viewer is GLB-only).
 *
 * @param {object|null|undefined} asset
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damOverride]
 * @returns {boolean}
 */
export function isRegistryModelGlbAsset(asset, damOverride) {
    if (!asset) return false
    const ext = extensionOf(asset)
    if (ext !== 'glb') return false
    const rows = Array.isArray(damOverride?.types_for_help)
        ? damOverride.types_for_help
        : getRegisteredTypesForHelp()
    for (const row of rows) {
        if (String(row?.key || '') !== 'model_glb') continue
        const exts = Array.isArray(row.extensions)
            ? row.extensions.map((e) => String(e).toLowerCase())
            : []
        if (exts.includes('glb')) {
            return true
        }
    }
    return false
}

/**
 * Signed GLB URL for model-viewer (registry GLB only).
 *
 * @param {object|null|undefined} asset
 * @returns {string|null}
 */
export function getRegistryModelGlbViewerDisplayUrl(asset) {
    const u = asset?.preview_3d_viewer_url
    if (typeof u !== 'string' || !u.trim()) {
        return null
    }
    return u.trim()
}

/**
 * Signed URL for model-viewer `src`: prefers `preview_3d_viewer_url`, else the asset's
 * native GLB `original` delivery URL when `preview_3d` metadata is not populated yet.
 *
 * @param {object|null|undefined} asset
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damOverride]
 * @returns {string|null}
 */
export function getRegistryModelGlbModelSourceUrl(asset, damOverride) {
    if (!isRegistryModelGlbAsset(asset, damOverride)) {
        return null
    }
    const fromPreview = getRegistryModelGlbViewerDisplayUrl(asset)
    if (fromPreview) {
        return fromPreview
    }
    const orig = typeof asset?.original === 'string' ? asset.original.trim() : ''
    return orig !== '' ? orig : null
}

/**
 * Whether to mount the realtime GLB viewer (Phase 5B).
 *
 * @param {object|null|undefined} asset
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damOverride]
 * @param {boolean} [dam3dEnabled=false] — {@link HandleInertiaRequests} `dam_3d_enabled`
 * @returns {boolean}
 */
export function shouldShowRealtimeGlbModelViewer(asset, damOverride, dam3dEnabled) {
    if (!dam3dEnabled) return false
    return getRegistryModelGlbModelSourceUrl(asset, damOverride) != null
}
