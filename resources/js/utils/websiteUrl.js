/**
 * Normalize website URL for display and API: trim and prepend https:// when no scheme.
 * Matches backend App\Support\WebsiteUrlNormalizer.
 *
 * @param {string|null|undefined} input
 * @returns {string|null} null when empty after trim
 */
export function normalizeWebsiteUrl(input) {
    if (input == null) return null
    const s = String(input).trim()
    if (s === '') return null
    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) {
        return s
    }
    return `https://${s.replace(/^\/+/, '')}`
}
