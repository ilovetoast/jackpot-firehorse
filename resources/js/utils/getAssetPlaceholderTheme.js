/**
 * Deterministic branded placeholder theme for asset grid processing tiles.
 * Merges CSS custom properties onto the shared backdrop style for optional use in CSS.
 */

import {
    getAssetPlaceholderBackdropStyle,
    getAssetPlaceholderHue,
    getPlaceholderVariationIndex,
    parseBrandHueFromHex,
} from './assetPlaceholderHue.js'

const JACKPOT_FALLBACK = '#6366f1'

/**
 * @param {unknown} value
 * @param {string} [fallback='#6366f1']
 * @returns {string} Normalized `#rrggbb`
 */
export function sanitizeHexColor(value, fallback = JACKPOT_FALLBACK) {
    const raw = String(value ?? '').trim()
    if (!raw) return fallback
    let s = raw.startsWith('#') ? raw.slice(1) : raw
    if (!/^[0-9a-fA-F]{3}$/.test(s) && !/^[0-9a-fA-F]{6}$/.test(s)) {
        return fallback
    }
    if (s.length === 3) {
        s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2]
    }
    return `#${s.toLowerCase()}`
}

/**
 * @typedef {{ primary_color?: string, primaryColor?: string, accent_color?: string, accentColor?: string }} BrandThemeInput
 * @param {object|null|undefined} asset
 * @param {BrandThemeInput} [brandTheme]
 * @returns {{ surfaceStyle: Record<string, string | number | undefined> }}
 */
export function getAssetPlaceholderTheme(asset, brandTheme = {}) {
    const primary = sanitizeHexColor(
        brandTheme.primary_color ?? brandTheme.primaryColor ?? JACKPOT_FALLBACK,
        JACKPOT_FALLBACK,
    )
    const accent = sanitizeHexColor(brandTheme.accent_color ?? brandTheme.accentColor ?? primary, primary)

    const surfaceStyle = getAssetPlaceholderBackdropStyle(asset, primary)
    const hue = getAssetPlaceholderHue(asset, primary)
    const accentHue = parseBrandHueFromHex(accent)

    const v = getPlaceholderVariationIndex(asset)
    const satNudge = ((v >> 4) % 7) - 3
    const lightNudge = ((v >> 9) % 9) - 4

    const bg1 = `hsl(${hue} ${Math.min(44, 34 + satNudge * 0.6)}% ${15.5 + lightNudge * 0.22}%)`
    const bg2 = `hsl(${(hue + 5 + 360) % 360} ${28 + satNudge * 0.45}% ${10.5 + lightNudge * 0.18}%)`
    const bg3 = `hsl(${(hue + 11 + 360) % 360} ${22 + satNudge * 0.35}% ${6.8 + lightNudge * 0.14}%)`

    return {
        surfaceStyle: {
            ...surfaceStyle,
            '--asset-placeholder-bg-1': bg1,
            '--asset-placeholder-bg-2': bg2,
            '--asset-placeholder-bg-3': bg3,
            '--asset-placeholder-accent': `hsl(${accentHue} 46% 52%)`,
            '--asset-placeholder-text': 'hsla(220, 22%, 96%, 0.94)',
            '--asset-placeholder-sheen': 'rgba(255, 255, 255, 0.17)',
        },
    }
}
