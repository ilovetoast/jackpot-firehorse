/**
 * Normalize freeform tag input to canonical tag string (matches TagUploadInput / backend expectations).
 * @param {string} raw
 * @returns {string} normalized tag or '' if nothing usable remains
 */
export function normalizeTagString(raw) {
    if (raw == null || typeof raw !== 'string') return ''
    return raw
        .toLowerCase()
        .trim()
        .replace(/[^\w\s\-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
}
