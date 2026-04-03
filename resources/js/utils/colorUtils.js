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
 * Get appropriate text color (white or black) based on background color
 * Uses WCAG contrast ratio guidelines - returns white for dark backgrounds, black for light
 * @param {string} backgroundColor - Hex color string
 * @returns {string} '#ffffff' for dark backgrounds, '#000000' for light backgrounds
 */
export function getContrastTextColor(backgroundColor) {
    if (!backgroundColor) return '#ffffff' // Default to white if no color
    
    const luminance = getLuminance(backgroundColor)
    // If luminance is less than 0.5, it's a dark color, use white text
    // If luminance is 0.5 or greater, it's a light color, use black text
    return luminance < 0.5 ? '#ffffff' : '#000000'
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
