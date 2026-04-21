import JSZip from 'jszip'

/**
 * @param {{ path: string, blob: Blob }[]} entries flat paths only (no slashes in path recommended)
 * @param {string | null} readme optional HANDOFF.txt body
 */
export async function buildRasterBundleZip(entries, readme) {
    const zip = new JSZip()
    for (const e of entries) {
        zip.file(e.path, e.blob)
    }
    if (readme != null && readme !== '') {
        zip.file('HANDOFF.txt', readme)
    }
    return zip.generateAsync({
        type: 'blob',
        compression: 'DEFLATE',
        compressionOptions: { level: 6 },
    })
}
