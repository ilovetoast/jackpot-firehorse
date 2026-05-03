/**
 * Normalized per-file and batch upload progress for Add Asset / UploadTray.
 * Ensures completed byte uploads contribute 100% so the bar cannot sit at ~88% when the queue is done.
 */

/**
 * @param {Record<string, unknown>} item — v2UploadManager tray item
 * @returns {number} 0–100
 */
export function getUploadItemProgress(item) {
    if (!item || typeof item !== 'object') return 0
    const life = String(item.lifecycle || '')
    const us = String(item.uploadStatus || '')

    if (us === 'skipped') {
        return 0
    }
    if (us === 'failed' || life === 'failed') {
        return 100
    }
    if (us === 'ready' || us === 'succeeded') {
        return 100
    }
    if (life === 'finalized') {
        return 100
    }
    if (life === 'uploaded' && us === 'complete') {
        return 100
    }
    if (life === 'finalizing' || us === 'processing') {
        return 95
    }
    if (us === 'uploading') {
        return Math.min(100, Math.max(0, Math.round(Number(item.progress) || 0)))
    }
    if (us === 'queued' || life === 'selected' || life === 'pending_preflight') {
        return 0
    }
    return Math.min(100, Math.max(0, Math.round(Number(item.progress) || 0)))
}

/**
 * True when no further byte upload / finalize work is expected for this item (success, fail, or skip).
 * @param {Record<string, unknown>} item
 */
export function isUploadItemTransferTerminal(item) {
    if (!item || typeof item !== 'object') return false
    const life = String(item.lifecycle || '')
    const us = String(item.uploadStatus || '')
    if (us === 'skipped') return true
    if (us === 'failed' || life === 'failed') return true
    if (us === 'ready' || us === 'succeeded') return true
    if (life === 'finalized') return true
    if (life === 'uploaded' && us === 'complete') return true
    return false
}

/**
 * Overall queue percent (0–100). When every item is transfer-terminal, always 100.
 * Skipped files are excluded from the average denominator.
 *
 * @param {Array<Record<string, unknown>>|null|undefined} items
 * @returns {number}
 */
export function computeOverallBatchUploadPercent(items) {
    const list = Array.isArray(items) ? items : []
    if (list.length === 0) return 0
    if (list.every(isUploadItemTransferTerminal)) {
        return 100
    }

    let sum = 0
    let denom = 0
    for (const it of list) {
        if (String(it.uploadStatus || '') === 'skipped') {
            continue
        }
        denom += 1
        sum += getUploadItemProgress(it)
    }
    if (denom === 0) {
        return 100
    }
    return Math.min(100, Math.max(0, sum / denom))
}
