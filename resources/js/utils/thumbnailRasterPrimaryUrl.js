/**
 * Single resolution path for the primary raster URL shown in {@link ../Components/ThumbnailPreview.jsx}:
 * 3D registry poster first, then final/LQIP/SVG-original rules (unchanged for non-3D).
 */

import { originalImageGridFallbackUrl } from './originalImageGridFallbackUrl.js'
import {
    getRegistryModel3dPosterDisplayUrl,
    isRegistryModel3dAsset,
    isRegistryModel3dPosterStub,
} from './resolveAsset3dPreviewImage.js'

/**
 * @param {object|null|undefined} asset
 * @param {boolean} preferLargeForVector
 * @param {Set<string>|null|undefined} failedThumbnailUrls
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} damFileTypes
 * @returns {string|null}
 */
export function resolveRasterPrimaryThumbnailUrl(
    asset,
    preferLargeForVector,
    failedThumbnailUrls,
    damFileTypes,
) {
    if (!asset) {
        return null
    }
    const poster = getRegistryModel3dPosterDisplayUrl(asset, failedThumbnailUrls, damFileTypes)
    if (poster) {
        return poster
    }

    // Stub pipeline uploads thumb/medium/large from the same synthetic master — hide those too in the grid.
    if (isRegistryModel3dAsset(asset, damFileTypes) && isRegistryModel3dPosterStub(asset)) {
        return null
    }

    const isSvg =
        asset.mime_type === 'image/svg+xml' ||
        String(asset.original_filename || '')
            .toLowerCase()
            .endsWith('.svg') ||
        asset.file_extension === 'svg'
    const useLarge = Boolean(preferLargeForVector && isSvg && asset.id)
    if (asset.final_thumbnail_url && useLarge) {
        return asset.thumbnail_url_large ?? asset.final_thumbnail_url
    }
    if (asset.final_thumbnail_url) {
        return asset.final_thumbnail_url
    }
    const ts = String(asset.thumbnail_status?.value || asset.thumbnail_status || '').toLowerCase()
    if (asset.thumbnail_url && ts === 'completed') {
        return asset.thumbnail_url
    }
    return originalImageGridFallbackUrl(asset)
}
