/**
 * Deterministic branded placeholder hues for asset grid tiles.
 * Hash(asset.id || filename) → offset within ±10° of brand primary hue (no random per render).
 */

export function fnv1a32(str) {
    const s = String(str ?? '')
    let h = 2166136261 >>> 0
    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i)
        h = Math.imul(h, 16777619) >>> 0
    }
    return h >>> 0
}

function hexToRgb(hex) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(String(hex || '').trim())
    if (!m) return { r: 99, g: 102, b: 241 }
    return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) }
}

/** Hue in [0, 360) from sRGB hex (fallback indigo). */
export function parseBrandHueFromHex(hex) {
    const { r, g, b } = hexToRgb(hex)
    const rn = r / 255
    const gn = g / 255
    const bn = b / 255
    const max = Math.max(rn, gn, bn)
    const min = Math.min(rn, gn, bn)
    const d = max - min
    if (d < 1e-6) return 255 // grayscale → default violet-ish
    let h = 0
    switch (max) {
        case rn:
            h = ((gn - bn) / d + (gn < bn ? 6 : 0)) / 6
            break
        case gn:
            h = ((bn - rn) / d + 2) / 6
            break
        default:
            h = ((rn - gn) / d + 4) / 6
            break
    }
    return (h * 360 + 360) % 360
}

function placeholderHashKey(asset) {
    if (asset?.id != null && asset.id !== '') return String(asset.id)
    const name =
        asset?.original_filename ||
        asset?.filename ||
        asset?.title ||
        asset?.name ||
        'asset'
    return String(name)
}

/** Deterministic 32-bit hash for per-asset micro-variation (saturation, lightness, etc.). */
export function getPlaceholderVariationIndex(asset) {
    return fnv1a32(placeholderHashKey(asset))
}

/**
 * @param {object|null|undefined} asset
 * @param {string} [brandPrimaryHex='#6366f1'] — brand primary (e.g. auth.activeBrand.primary_color)
 * @returns {number} Hue in [0, 360)
 */
export function getAssetPlaceholderHue(asset, brandPrimaryHex = '#6366f1') {
    const base = parseBrandHueFromHex(brandPrimaryHex)
    // offsets ≈ −14..+14° — visible per-tile variety while staying on-brand
    const spread = 29
    const h = fnv1a32(placeholderHashKey(asset))
    const offset = (h % spread) - Math.floor(spread / 2)
    return (base + offset + 360) % 360
}

/**
 * Multi-layer background for dark branded placeholder (under-thumbnail sweep):
 * brand-tinted body gradient, soft vignette, light scanline grain — no checker/conic grid.
 *
 * @param {number} hue — from {@link getAssetPlaceholderHue}
 * @param {string} [brandHex='#6366f1'] — active brand primary (kept for API stability)
 * @param {number} [lightnessJitterIndex=0] — deterministic micro lightness shift per asset
 * @returns {React.CSSProperties}
 */
export function getPlaceholderGradientStyle(hue, brandHex = '#6366f1', lightnessJitterIndex = 0) {
    void brandHex
    const h = Number.isFinite(hue) ? hue : 255
    const j = Number(lightnessJitterIndex) || 0
    const dL = ((j % 11) - 5) * 0.24

    const grain =
        'repeating-linear-gradient(0deg, rgba(255,255,255,0.028) 0px, transparent 1px, transparent 3px)'
    const vignette = `radial-gradient(120% 90% at 50% 0%, hsla(${h}, 40%, 22%, 0.35) 0%, transparent 55%)`
    const body = `linear-gradient(168deg, hsl(${h} 36% ${16 + dL * 0.45}%) 0%, hsl(${h} 28% ${10 + dL * 0.35}%) 46%, hsl(${h} 22% ${5.5 + dL * 0.25}%) 100%)`
    const baseBgL = Math.min(9, Math.max(4, 6 + dL * 0.14))

    return {
        backgroundImage: `${grain}, ${vignette}, ${body}`,
        backgroundColor: `hsl(${h} 24% ${baseBgL}%)`,
        backgroundSize: 'auto, auto, auto',
        backgroundPosition: '0 0, 0 0, 0 0',
        backgroundRepeat: 'repeat, no-repeat, no-repeat',
    }
}

/**
 * Under-thumbnail loading layer (same deterministic hue as full placeholder cards).
 * @param {object|null|undefined} asset
 * @param {string} [brandPrimaryHex]
 * @returns {React.CSSProperties}
 */
export function getAssetPlaceholderBackdropStyle(asset, brandPrimaryHex = '#6366f1') {
    const hue = getAssetPlaceholderHue(asset, brandPrimaryHex)
    const hash = getPlaceholderVariationIndex(asset)
    const lightnessJitterIndex = (hash >>> 6) % 211
    return getPlaceholderGradientStyle(hue, brandPrimaryHex, lightnessJitterIndex)
}
