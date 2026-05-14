/**
 * Strip internal IDs and noise from server/user messages before showing them on asset grid tiles.
 * Drawer / admin views can still show full `thumbnail_error` where appropriate.
 *
 * @param {unknown} text
 * @returns {string}
 */
export function sanitizeGridPreviewUserMessage(text) {
    if (text == null) return ''
    let s = String(text).trim()
    if (!s) return ''
    // UUIDs (v4-ish and Laravel ordered UUIDs)
    s = s.replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi, ' ')
    // Long hex / hash-like tokens
    s = s.replace(/\b[0-9a-f]{20,}\b/gi, ' ')
    s = s.replace(/\s{2,}/g, ' ').trim()
    return s
}
