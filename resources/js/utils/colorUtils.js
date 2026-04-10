/**
 * Calculate the relative luminance of a color using WCAG formula
 * @param {string} hexColor - Hex color string (e.g., "#FF0000" or "#f00")
 * @returns {number} Luminance value between 0 and 1
 */
export function getLuminance(hexColor) {
    if (!hexColor) return 0.5 // Default to medium if no color provided
    
    // Remove # if present
    let hex = hexColor.replace('#', '')
    
    // Convert 3-digit hex to 6-digit
    if (hex.length === 3) {
        hex = hex.split('').map(char => char + char).join('')
    }
    
    // Convert to RGB
    const r = parseInt(hex.substring(0, 2), 16) / 255
    const g = parseInt(hex.substring(2, 4), 16) / 255
    const b = parseInt(hex.substring(4, 6), 16) / 255
    
    // Apply gamma correction
    const [rLinear, gLinear, bLinear] = [r, g, b].map(val => {
        return val <= 0.03928 ? val / 12.92 : Math.pow((val + 0.055) / 1.055, 2.4)
    })
    
    // Calculate relative luminance (WCAG formula)
    return 0.2126 * rLinear + 0.7152 * gLinear + 0.0722 * bLinear
}

/**
 * Get the workspace button/accent color based on workspace_button_style setting.
 * Used for Add Asset button and primary actions in DAM (Assets, Deliverables, Collections).
 * @param {Object} brand - Brand object with workspace_button_style, primary_color, secondary_color, accent_color
 * @returns {string} Hex color for the workspace primary action
 */
export function getWorkspaceButtonColor(brand) {
    if (!brand) return '#6366f1'
    const style = brand.workspace_button_style ?? 'primary'
    if (style === 'primary') return brand.primary_color || '#6366f1'
    if (style === 'secondary') return brand.secondary_color || '#64748b'
    return brand.accent_color || '#6366f1' // accent
}

/**
 * Convert hex color to rgba string with given opacity.
 * @param {string} hexColor - Hex color (e.g. "#6366f1" or "6366f1")
 * @param {number} alpha - Opacity 0-1 (e.g. 0.25 for 25%)
 * @returns {string} rgba(r, g, b, alpha)
 */
export function hexToRgba(hexColor, alpha = 1) {
    if (!hexColor) return `rgba(99, 102, 241, ${alpha})` // indigo fallback
    let hex = String(hexColor).replace('#', '')
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('')
    const r = parseInt(hex.substring(0, 2), 16)
    const g = parseInt(hex.substring(2, 4), 16)
    const b = parseInt(hex.substring(4, 6), 16)
    return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

/**
 * Darken a hex color by subtracting from each RGB channel.
 * Matches the Add Execution / Add Asset button hover behavior.
 * @param {string} hexColor - Hex color (e.g. "#6366f1")
 * @param {number} amount - Amount to subtract from each channel (default 20)
 * @returns {string} Darkened hex color
 */
export function darkenColor(hexColor, amount = 20) {
    if (!hexColor) return '#4f46e5'
    let hex = String(hexColor).replace('#', '')
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('')
    let r = Math.max(0, parseInt(hex.substring(0, 2), 16) - amount)
    let g = Math.max(0, parseInt(hex.substring(2, 4), 16) - amount)
    let b = Math.max(0, parseInt(hex.substring(4, 6), 16) - amount)
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`
}

/**
 * Foreground (#fff vs #000) for solid UI backgrounds — picks whichever maximizes WCAG 2.1 contrast ratio.
 * Prefer this over luminance-only heuristics for saturated mid-light brand colors (e.g. cyan) on buttons.
 * @param {string} backgroundColor - Hex color string
 * @returns {'#ffffff'|'#000000'}
 */
export function getContrastTextColor(backgroundColor) {
    if (!backgroundColor) return '#ffffff'
    const bg = normalizeHexColor(backgroundColor)
    const ratioWhite = getContrastRatio('#ffffff', bg)
    const ratioBlack = getContrastRatio('#000000', bg)
    return ratioWhite >= ratioBlack ? '#ffffff' : '#000000'
}

/**
 * Brand settings tile / selector (same logic as BrandIconUnified).
 * @param {'gradient'|'solid'|'subtle'} style
 * @param {string|null|undefined} primary
 * @param {string|null|undefined} secondary
 * @returns {string} CSS background (gradient or solid color)
 */
export function resolveBrandIconBackground(style, primary, secondary) {
    const p = primary || '#6366f1'
    const s = secondary || '#8b5cf6'
    switch (style) {
        case 'gradient':
            return `linear-gradient(135deg, ${s !== p ? s : p}, ${p})`
        case 'solid':
            return p
        case 'subtle':
        default:
            return `linear-gradient(135deg, ${p}CC, ${p}55)`
    }
}

/**
 * Layered radial “cinematic” background for no-preview asset tiles — same language as Overview / Brand Guidelines
 * (dark base + primary/secondary glow bleeding into the tile).
 *
 * @param {string|null|undefined} primary
 * @param {string|null|undefined} secondary
 * @returns {string} CSS `background` value (multiple layers, comma-separated)
 */
export function buildBrandCinematicTileBackground(primary, secondary) {
    const p = primary || '#6366f1'
    const s = secondary || '#8b5cf6'
    return [
        'linear-gradient(165deg, rgba(0,0,0,0.34) 0%, transparent 42%, rgba(0,0,0,0.52) 100%)',
        `radial-gradient(ellipse 95% 75% at 30% 40%, ${hexToRgba(p, 0.14)} 0%, transparent 58%)`,
        `radial-gradient(circle at 78% 74%, ${hexToRgba(s, 0.19)} 0%, transparent 56%)`,
        `radial-gradient(circle at 14% 20%, ${hexToRgba(p, 0.24)} 0%, transparent 50%)`,
        '#0B0B0D',
    ].join(', ')
}

/**
 * Normalize to #RRGGBB for contrast math (invalid → indigo fallback).
 * @param {string|null|undefined} hexColor
 * @returns {string}
 */
export function normalizeHexColor(hexColor) {
    if (!hexColor || typeof hexColor !== 'string') return '#6366f1'
    let hex = hexColor.replace('#', '').trim()
    if (hex.length === 3) {
        hex = hex.split('').map((c) => c + c).join('')
    }
    if (hex.length !== 6 || !/^[0-9a-fA-F]{6}$/.test(hex)) return '#6366f1'
    return `#${hex}`
}

/**
 * HSL saturation 0–1 for a #RRGGBB color.
 * @param {string} hex6
 * @returns {number}
 */
function hexSaturation(hex6) {
    const h = normalizeHexColor(hex6).slice(1)
    const r = parseInt(h.slice(0, 2), 16) / 255
    const g = parseInt(h.slice(2, 4), 16) / 255
    const b = parseInt(h.slice(4, 6), 16) / 255
    const mx = Math.max(r, g, b)
    const mn = Math.min(r, g, b)
    if (mx === mn) return 0
    const l = (mx + mn) / 2
    return (mx - mn) / (l > 0.5 ? 2 - mx - mn : mx + mn)
}

/**
 * Pick accent vs primary vs secondary for high-visibility UI on near-black backgrounds.
 * Prefers the most saturated option; falls back to amber if all are very gray.
 *
 * @param {string|null|undefined} primaryHex
 * @param {string|null|undefined} accentHex
 * @param {string|null|undefined} secondaryHex
 * @param {string} [fallbackHex='#f59e0b']
 * @returns {string} #RRGGBB
 */
export function pickProminentAccentColor(primaryHex, accentHex, secondaryHex, fallbackHex = '#f59e0b') {
    const candidates = [accentHex, secondaryHex, primaryHex]
        .map((c) => (c && String(c).trim() ? normalizeHexColor(c) : null))
        .filter((c, i, arr) => c && arr.indexOf(c) === i)

    if (candidates.length === 0) return normalizeHexColor(fallbackHex)

    const score = (hex) => hexSaturation(hex) + Math.min(getLuminance(hex), 0.9) * 0.12

    let best = candidates[0]
    let bestScore = score(best)
    for (let i = 1; i < candidates.length; i++) {
        const sc = score(candidates[i])
        if (sc > bestScore) {
            best = candidates[i]
            bestScore = sc
        }
    }

    // Very gray brand kits → keep amber so the alert still reads as “attention”
    return bestScore >= 0.14 ? best : normalizeHexColor(fallbackHex)
}

/**
 * WCAG 2.1 contrast ratio between two sRGB hex colors.
 * @param {string} foregroundHex
 * @param {string} backgroundHex
 * @returns {number}
 */
export function getContrastRatio(foregroundHex, backgroundHex) {
    const L1 = getLuminance(normalizeHexColor(foregroundHex))
    const L2 = getLuminance(normalizeHexColor(backgroundHex))
    const lighter = Math.max(L1, L2)
    const darker = Math.min(L1, L2)
    return (lighter + 0.05) / (darker + 0.05)
}

/** Approximate “card interior” on cinematic overview (#0B0B0D + ~6% white glass). */
const OVERVIEW_ICON_SURFACE_HEX = '#1a1b1e'

/**
 * Pick a hex for icons on dark overview cards: try primary, secondary, accent; if none meets
 * contrast vs a dark reference surface, use a light neutral (still reads as “accent” in context).
 *
 * @param {string|null|undefined} primaryHex
 * @param {{ secondary?: string|null, accent?: string|null, surface?: string, minRatio?: number, fallback?: string }} [options]
 * @returns {string}
 */
export function resolveOverviewIconColor(primaryHex, options = {}) {
    const surface = options.surface || OVERVIEW_ICON_SURFACE_HEX
    const minRatio = options.minRatio ?? 3
    const fallback = options.fallback || '#e2e8f0'
    const candidates = [primaryHex, options.secondary, options.accent].filter(
        (c) => c != null && String(c).trim() !== ''
    )
    for (const raw of candidates) {
        const c = normalizeHexColor(raw)
        if (getContrastRatio(c, surface) >= minRatio) {
            return c
        }
    }
    return fallback
}
