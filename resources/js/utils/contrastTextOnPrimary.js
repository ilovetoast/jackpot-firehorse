/**
 * Pick readable text (near-white or near-black) for UI on top of a solid brand primary fill.
 * @param {string | null | undefined} hex
 * @returns {{ color: string, isDarkBackground: boolean }}
 */
export function contrastTextOnPrimary(hex) {
    const fallback = { color: '#f8fafc', isDarkBackground: true }
    if (!hex || typeof hex !== 'string') return fallback
    let h = hex.replace('#', '').trim()
    if (h.length === 3) {
        h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]
    }
    if (h.length !== 6 || !/^[0-9a-fA-F]{6}$/.test(h)) return fallback
    const r = parseInt(h.slice(0, 2), 16) / 255
    const g = parseInt(h.slice(2, 4), 16) / 255
    const b = parseInt(h.slice(4, 6), 16) / 255
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b
    const isDarkBackground = luminance < 0.5
    return {
        color: isDarkBackground ? '#f8fafc' : '#0f172a',
        isDarkBackground,
    }
}
