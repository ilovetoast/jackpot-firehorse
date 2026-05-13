import { supportsThumbnail } from './thumbnailUtils.js'
import { getAssetCardVisualState, hasServerRasterThumbnail } from './assetCardVisualState.js'

function extensionForAsset(a) {
    return (a?.file_extension || a?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

/** Matches {@link computeThumbnailPipelineGridSummary} “attention” count (current page only). */
export function assetNeedsThumbnailPipelineAttention(a) {
    if (!a?.id) return false
    const ts = String(a?.thumbnail_status?.value ?? a.thumbnail_status ?? '').toLowerCase()
    return ts === 'failed' || Boolean(a?.thumbnail_error) || a?.health_status === 'critical'
}

/**
 * Subtle toolbar counts: assets still waiting on server previews vs. terminal problems.
 * @param {Array<object>|null|undefined} assets
 * @returns {{ processing: number, attention: number, rawProcessing: number }}
 */
export function computeThumbnailPipelineGridSummary(assets) {
    let processing = 0
    let attention = 0
    let rawProcessing = 0
    if (!Array.isArray(assets)) return { processing: 0, attention: 0, rawProcessing: 0 }
    for (const a of assets) {
        if (!a?.id) continue
        const ext = extensionForAsset(a)
        const mime = a.mime_type || ''
        const ts = String(a?.thumbnail_status?.value ?? a.thumbnail_status ?? '').toLowerCase()

        if (assetNeedsThumbnailPipelineAttention(a)) {
            attention += 1
            continue
        }

        if (!supportsThumbnail(mime, ext)) continue
        if (hasServerRasterThumbnail(a)) continue
        if (ts === 'skipped') continue

        if (ts === 'pending' || ts === 'processing' || ts === '') {
            processing += 1
            const vs = getAssetCardVisualState(a, { ephemeralLocalPreviewUrl: null })
            if (vs.kind === 'raw_processing') rawProcessing += 1
        }
    }
    return { processing, attention, rawProcessing }
}
