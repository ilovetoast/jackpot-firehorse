/**
 * Thumbnail URL resolution for multi-mode delivery (`thumbnail_mode_urls` from API).
 * Kept free of damFileTypes so Node tests can import this module directly.
 */

/**
 * @param {Record<string, string>|null|undefined} bucket
 * @param {'thumb'|'medium'|'large'} style
 * @returns {string|null}
 */
function pickThumbnailStyleFromBucket(bucket, style) {
    if (!bucket || typeof bucket !== 'object') {
        return null
    }
    if (bucket[style]) {
        return bucket[style]
    }
    if (style !== 'medium' && bucket.medium) {
        return bucket.medium
    }
    if (bucket.thumb) {
        return bucket.thumb
    }
    if (bucket.large) {
        return bucket.large
    }
    return null
}

/**
 * URL from a single mode bucket only — no fallback to `original` or legacy flat fields.
 * Use for comparison UI and anywhere “this mode must exist” (e.g. enhanced while still generating).
 *
 * @param {Object|null} asset
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @param {'original'|'preferred'|'enhanced'|'presentation'} [mode='enhanced']
 * @returns {string|null}
 */
export function getThumbnailUrlModeOnly(asset, style = 'medium', mode = 'enhanced') {
    if (!asset) {
        return null
    }
    const modes = asset.thumbnail_mode_urls
    if (!modes || typeof modes !== 'object' || !modes[mode]) {
        return null
    }
    return pickThumbnailStyleFromBucket(modes[mode], style)
}

/**
 * Resolve a delivery URL for a thumbnail style and pipeline mode, with graceful fallbacks.
 * Order: requested mode + style → original mode (same style keys) → legacy flat props → null (UI placeholder).
 *
 * @param {Object|null} asset
 * @param {'thumb'|'medium'|'large'} [style='medium']
 * @param {'original'|'preferred'|'enhanced'} [mode='original']
 * @returns {string|null}
 */
export function getThumbnailUrl(asset, style = 'medium', mode = 'original') {
    if (!asset) {
        return null
    }
    const modes = asset.thumbnail_mode_urls

    const pickFromMode = (m) => {
        if (!modes || typeof modes !== 'object' || !m) {
            return null
        }
        return pickThumbnailStyleFromBucket(modes[m], style)
    }

    const primary = pickFromMode(mode)
    if (primary) {
        return primary
    }
    const original = pickFromMode('original')
    if (original) {
        return original
    }

    const hasModeMap = modes && typeof modes === 'object' && Object.keys(modes).length > 0
    if (!hasModeMap) {
        if (style === 'large') {
            return (
                asset.thumbnail_url_large ||
                asset.thumbnail_large ||
                asset.final_thumbnail_url ||
                asset.thumbnail_url ||
                null
            )
        }
        return (
            asset.thumbnail_medium ||
            asset.final_thumbnail_url ||
            asset.thumbnail_url ||
            asset.thumbnail_small ||
            null
        )
    }

    return null
}
