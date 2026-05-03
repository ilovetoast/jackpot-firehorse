/**
 * Whether a File may use URL.createObjectURL for the asset grid fallback (browser-decodable raster only).
 */

const ALLOWED_MIME = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])

const ALLOWED_EXT = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif'])

/**
 * @param {File|Blob|null|undefined} file
 */
export function shouldRegisterGridBlobPreview(file) {
    if (!file || typeof file !== 'object') return false
    const mime = String(file.type || '').toLowerCase()
    if (mime && ALLOWED_MIME.has(mime)) return true
    const name = typeof file.name === 'string' ? file.name : ''
    const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
    return ext && ALLOWED_EXT.has(ext)
}
