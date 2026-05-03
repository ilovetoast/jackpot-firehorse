/**
 * Tray / upload-queue aggregate counts (mirrors UploadTray bucket logic).
 *
 * @param {Array<Record<string, unknown>>} items
 * @returns {{
 *   uploading: number,
 *   processing: number,
 *   uploaded: number,
 *   ready: number,
 *   failed: number,
 *   skipped: number,
 *   queued: number,
 * }}
 */
export function computeUploadCounts(items) {
    let uploading = 0
    let processing = 0
    let uploaded = 0
    let ready = 0
    let failed = 0
    let skipped = 0
    let queued = 0

    for (const it of items || []) {
        const life = it.lifecycle || ''
        if (it.uploadStatus === 'skipped') {
            skipped++
            continue
        }
        if (it.uploadStatus === 'failed') {
            failed++
            continue
        }
        if (life === 'finalized') {
            const t = it.pipelineThumbStatus
            const terminal = t === 'completed' || t === 'failed'
            if (it.assetId && !terminal) {
                processing++
                continue
            }
            ready++
            continue
        }
        if (life === 'finalizing' || it.uploadStatus === 'processing') {
            processing++
            continue
        }
        if (life === 'uploaded' && it.uploadStatus === 'complete') {
            uploaded++
            continue
        }
        if (it.uploadStatus === 'uploading') {
            uploading++
            continue
        }
        if (it.uploadStatus === 'queued') {
            queued++
            continue
        }
        if (it.uploadStatus === 'complete') {
            uploaded++
            continue
        }
    }

    return { uploading, processing, uploaded, ready, failed, skipped, queued }
}
