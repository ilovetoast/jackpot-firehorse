/**
 * Whether the browser can reliably show a <img> preview from a local File (blob URL).
 * RAW, PSD, HEIC, etc. upload fine but won’t decode in the tray — use a file-type tile instead.
 */

const NO_LOCAL_BROWSER_PREVIEW = new Set([
    'cr2',
    'cr3',
    'nef',
    'nrw',
    'arw',
    'raf',
    'orf',
    'rw2',
    'dng',
    'heic',
    'heif',
    'psd',
    'tif',
    'tiff',
])

/**
 * @param {string} [fileExtension] — without dot
 * @param {string} [mimeType]
 */
export function isLocalImagePreviewUnsupported(fileExtension, mimeType) {
    const e = String(fileExtension || '')
        .toLowerCase()
        .replace(/^\./, '')
    if (e && NO_LOCAL_BROWSER_PREVIEW.has(e)) {
        return true
    }
    const m = String(mimeType || '').toLowerCase()
    if (m.includes('x-canon-cr') || m.includes('fuji-raw') || m.includes('raw')) {
        return true
    }
    return false
}
