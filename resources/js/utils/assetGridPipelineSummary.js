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
 * Scroll the first asset on the current page that needs pipeline attention into view.
 * Scopes to the element with `data-help="asset-grid"` so the toolbar CTA does not match unrelated nodes;
 * falls back to `[data-asset-card][data-asset-id="…"]` when markers are missing from the DOM.
 *
 * @param {Array<object>|null|undefined} assets Current grid page assets (same list used for counts).
 */
export function scrollToFirstPipelineAttentionAssetInGrid(assets) {
    if (typeof document === 'undefined') return
    const grid = document.querySelector('[data-help="asset-grid"]')
    const marked =
        grid?.querySelector('[data-pipeline-attention="1"]') ??
        document.querySelector('[data-pipeline-attention="1"]')
    if (marked) {
        marked.scrollIntoView({ behavior: 'smooth', block: 'center' })
        return
    }
    const list = Array.isArray(assets) ? assets : []
    for (const a of list) {
        if (!assetNeedsThumbnailPipelineAttention(a)) continue
        const id = a?.id
        if (id == null) continue
        const escaped =
            typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
                ? CSS.escape(String(id))
                : String(id).replace(/\\/g, '\\\\').replace(/"/g, '\\"')
        const byId =
            grid?.querySelector(`[data-asset-card][data-asset-id="${escaped}"]`) ??
            document.querySelector(`[data-asset-card][data-asset-id="${escaped}"]`)
        if (byId) {
            byId.scrollIntoView({ behavior: 'smooth', block: 'center' })
            return
        }
    }
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
