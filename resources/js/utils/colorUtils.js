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
    const style = brand.workspace_button_style ?? brand.settings?.button_style ?? 'primary'
    if (style === 'primary') return brand.primary_color || '#6366f1'
    if (style === 'secondary') return brand.secondary_color || '#64748b'
    if (style === 'accent') return brand.accent_color || '#6366f1'
    if (style === 'white') return '#ffffff'
    if (style === 'black') return '#000000'
    return brand.accent_color || '#6366f1'
}

/**
 * Single “inked” tone derived from the workspace accent/base (library row selection, etc.).
 * Pure black/white brand picks map to neutrals so darken() does not collapse to flat black.
 * @param {string} baseHex
 * @returns {string} Hex background
 */
export function getWorkspaceContextualTone(baseHex) {
    const base = baseHex || '#6366f1'
    const lum = getLuminance(base)
    const sat = hexSaturation(normalizeHexColor(base))
    if (lum < 0.06 && sat < 0.15) return '#171717'
    if (lum > 0.94) return darkenColor(base, 14)
    return darkenColor(base, 20)
}

/**
 * Resting + hover backgrounds for the primary workspace action (Add Asset).
 * Resting = darken(20), hover = darken(35) — gives depth while preserving the brand hue.
 * Only truly achromatic near-black (low saturation) maps to neutral dark; saturated dark
 * colors (e.g. deep purple) are darkened normally so the hue is preserved.
 * @param {Object} brand
 * @returns {{ resting: string, hover: string }}
 */
export function getWorkspacePrimaryActionButtonColors(brand) {
    const base = getWorkspaceButtonColor(brand)
    const lum = getLuminance(base)
    const sat = hexSaturation(normalizeHexColor(base))

    // True near-black (low luminance AND low saturation) → neutral dark
    if (lum < 0.06 && sat < 0.15) {
        const resting = '#171717'
        return { resting, hover: lightenColor(resting, 34) }
    }
    // Near-white → darken so button is visible on white backgrounds
    if (lum > 0.94) {
        return { resting: darkenColor(base, 14), hover: darkenColor(base, 26) }
    }
    // Standard: darkened resting state with deeper hover
    return { resting: darkenColor(base, 20), hover: darkenColor(base, 35) }
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
 * Lighten a hex color by adding to each RGB channel (mirrors darkenColor).
 * @param {string} hexColor
 * @param {number} amount
 * @returns {string} Hex color
 */
export function lightenColor(hexColor, amount = 20) {
    if (!hexColor) return '#fafafa'
    let hex = String(hexColor).replace('#', '')
    if (hex.length === 3) hex = hex.split('').map((c) => c + c).join('')
    let r = Math.min(255, parseInt(hex.substring(0, 2), 16) + amount)
    let g = Math.min(255, parseInt(hex.substring(2, 4), 16) + amount)
    let b = Math.min(255, parseInt(hex.substring(4, 6), 16) + amount)
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
export function buildBrandCinematicTileBackground(primary, secondary, accent = null) {
    const { first, second, useStrongerAlpha } = pickCinematicGlowPair(primary, secondary, accent)
    const opP = useStrongerAlpha ? 0.2 : 0.14
    const opS = useStrongerAlpha ? 0.25 : 0.19
    const opP2 = useStrongerAlpha ? 0.32 : 0.24
    return [
        'linear-gradient(165deg, rgba(0,0,0,0.34) 0%, transparent 42%, rgba(0,0,0,0.52) 100%)',
        `radial-gradient(ellipse 95% 75% at 30% 40%, ${hexToRgba(first, opP)} 0%, transparent 58%)`,
        `radial-gradient(circle at 78% 74%, ${hexToRgba(second, opS)} 0%, transparent 56%)`,
        `radial-gradient(circle at 14% 20%, ${hexToRgba(first, opP2)} 0%, transparent 50%)`,
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
 * When primary & secondary are both low-chroma, radial glows on #0B0B0D look flat black.
 * If accent is clearly more saturated, use it (plus a shifted partner) so cinematic shells keep color.
 *
 * @returns {{ first: string, second: string, useStrongerAlpha: boolean }}
 */
function pickCinematicGlowPair(primaryHex, secondaryHex, accentHex) {
    const primary = normalizeHexColor(primaryHex || '#6366f1')
    const secondary = normalizeHexColor(secondaryHex || '#8b5cf6')
    const accent =
        accentHex != null && String(accentHex).trim() !== '' ? normalizeHexColor(accentHex) : null

    const sp = hexSaturation(primary)
    const ss = hexSaturation(secondary)
    const sa = accent ? hexSaturation(accent) : 0

    const dullThreshold = 0.11
    const maxPrimarySecondary = Math.max(sp, ss)
    const dullPair = maxPrimarySecondary < dullThreshold
    const accentWins = accent && sa >= 0.135 && sa > maxPrimarySecondary + 0.02

    if (dullPair && accentWins) {
        let second = normalizeHexColor(lightenColor(accent, 26))
        if (getLuminance(second) > 0.78) {
            second = normalizeHexColor(darkenColor(accent, 22))
        }
        if (second.toLowerCase() === accent.toLowerCase()) {
            second = normalizeHexColor(lightenColor(accent, 14))
        }
        return { first: accent, second, useStrongerAlpha: true }
    }

    return { first: primary, second: secondary, useStrongerAlpha: false }
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

/** Product default accent (Tailwind `indigo-600`) when tenant colors are unusable on white. */
export const SITE_DEFAULT_ACCENT_HEX = '#4f46e5'

/**
 * Return a version of accentHex that meets WCAG AA contrast (4.5:1) against white.
 * Progressively darkens the color; falls back to slate-700 if the hue is too washed-out.
 * Use for accent-colored text or borders on white surfaces.
 *
 * @param {string|null|undefined} accentHex
 * @param {number} [minRatio=4.5]
 * @returns {string} #RRGGBB safe for use on white
 */
export function ensureAccentContrastOnWhite(accentHex, minRatio = 4.5) {
    const bg = '#ffffff'
    const base = accentHex && String(accentHex).trim()
        ? normalizeHexColor(accentHex)
        : SITE_DEFAULT_ACCENT_HEX

    if (getContrastRatio(base, bg) >= minRatio) return base

    let candidate = base
    for (let i = 0; i < 10; i++) {
        candidate = normalizeHexColor(darkenColor(candidate, 18))
        if (getContrastRatio(candidate, bg) >= minRatio) return candidate
    }
    return '#334155' // slate-700 — always safe on white
}

const SPINNER_ON_WHITE_BG = '#ffffff'

/**
 * Pick a hex for a loading spinner on a white grid footer: prefer brand primary when contrast vs white
 * is sufficient, then secondary / accent, then progressively darkened primary, then slate, then site default.
 *
 * @param {string|null|undefined} brandPrimaryHex
 * @param {{ secondary?: string|null, accent?: string|null, minRatio?: number }} [options]
 * @returns {string} #RRGGBB
 */
/**
 * Cinematic workspace sidebar / Overview shell — dual radial glows from brand colors on near-black.
 * When primary & secondary are desaturated, uses accent (if vibrant) so the shell is not flat black.
 *
 * @param {string|null|undefined} primaryHex
 * @param {string|null|undefined} secondaryHex
 * @param {string|null|undefined} [accentHex] — brand accent; optional but recommended for dull primaries
 * @returns {string} CSS `background` value
 */
export function workspaceOverviewBackdropCss(primaryHex, secondaryHex, accentHex = null) {
    const { first, second, useStrongerAlpha } = pickCinematicGlowPair(primaryHex, secondaryHex, accentHex)
    const p6 = normalizeHexColor(first).replace('#', '').toLowerCase()
    const s6 = normalizeHexColor(second).replace('#', '').toLowerCase()
    const a1 = useStrongerAlpha ? '40' : '33'
    const a2 = useStrongerAlpha ? '36' : '33'
    return `radial-gradient(circle at 20% 20%, #${p6}${a1}, transparent), radial-gradient(circle at 80% 80%, #${s6}${a2}, transparent), #0B0B0D`
}

/**
 * Resolve the cinematic accent color for dark cinematic layouts (overview, onboarding, etc.)
 * based on the brand's `settings.cinematic_accent_color_role` preference.
 *
 * "auto" (default) picks the most visible of primary → secondary → accent via ensureDarkModeContrast.
 * Explicit roles ("primary", "secondary", "accent") use that color, still guaranteeing dark-mode contrast.
 *
 * @param {Object} brand
 * @param {string} [fallback='#6366f1']
 * @returns {string} #RRGGBB safe for dark backgrounds
 */
export function resolveCinematicAccentColor(brand, fallback = '#6366f1') {
    if (!brand) return fallback

    const settings = brand.settings && typeof brand.settings === 'object' ? brand.settings : {}
    const role = settings.cinematic_accent_color_role || 'auto'

    const p = brand.primary_color || null
    const s = brand.secondary_color || null
    const a = brand.accent_color || null

    if (role === 'primary' && p) return ensureDarkModeContrast(p, fallback, 3)
    if (role === 'secondary' && s) return ensureDarkModeContrast(s, fallback, 3)
    if (role === 'accent' && a) return ensureDarkModeContrast(a, fallback, 3)

    // auto: try primary first, then secondary, then accent
    for (const c of [p, s, a]) {
        if (!c) continue
        const safe = ensureDarkModeContrast(c, null, 3)
        if (safe) return safe
    }
    return fallback
}

/**
 * @param {{ nav_color?: string|null, primary_color?: string|null, secondary_color?: string|null, accent_color?: string|null, settings?: Record<string, unknown>|null }} [brand]
 * @returns {{ isCinematic: boolean, sidebarColor: string, backdropCss: string|null }}
 */
export function resolveWorkspaceSidebarSurface(brand) {
    const settings = brand?.settings && typeof brand.settings === 'object' ? brand.settings : {}
    const isCinematic = settings.workspace_sidebar_style === 'cinematic'
    const primary = brand?.primary_color || '#6366f1'
    const secondary = brand?.secondary_color || brand?.accent_color || primary
    const accent = brand?.accent_color || null
    const sidebarColor = brand?.nav_color || brand?.primary_color || '#1f2937'
    const backdropCss = isCinematic ? workspaceOverviewBackdropCss(primary, secondary, accent) : null
    return { isCinematic, sidebarColor, backdropCss }
}

export function resolveSpinnerColorOnWhite(brandPrimaryHex, options = {}) {
    const minRatio = options.minRatio ?? 2.85
    const bg = SPINNER_ON_WHITE_BG

    const tryColor = (raw) => {
        if (raw == null || String(raw).trim() === '') return null
        const c = normalizeHexColor(raw)
        return getContrastRatio(c, bg) >= minRatio ? c : null
    }

    const primaryOk = tryColor(brandPrimaryHex)
    if (primaryOk) return primaryOk

    const secondaryOk = tryColor(options.secondary)
    if (secondaryOk) return secondaryOk

    const accentOk = tryColor(options.accent)
    if (accentOk) return accentOk

    const base = brandPrimaryHex && String(brandPrimaryHex).trim() ? normalizeHexColor(brandPrimaryHex) : null
    if (base) {
        let c = base
        for (let i = 0; i < 6; i++) {
            c = normalizeHexColor(darkenColor(c, 16 + i * 12))
            if (getContrastRatio(c, bg) >= minRatio) {
                return c
            }
        }
    }

    const slate = '#475569'
    if (getContrastRatio(slate, bg) >= minRatio) {
        return slate
    }

    return normalizeHexColor(SITE_DEFAULT_ACCENT_HEX)
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

/**
 * Ensure a brand color has sufficient visibility on the dark app shell (#0B0B0D).
 * For dark brand colors (black, near-black), progressively lightens saturated colors
 * or falls back to the Jackpot accent for achromatic near-blacks.
 *
 * Use this for UI chrome (progress bars, buttons, links, icons) on the dark theme
 * where the brand primary may be black or very dark.
 *
 * @param {string|null|undefined} hexColor
 * @param {string} [fallback='#6366f1']
 * @param {number} [minRatio=3]
 * @returns {string} #RRGGBB safe for dark backgrounds
 */
export function ensureDarkModeContrast(hexColor, fallback = '#6366f1', minRatio = 3) {
    const SITE_FALLBACK = '#6366f1'
    const surface = '#0B0B0D'

    const resolve = (hex) => {
        if (!hex || typeof hex !== 'string' || !hex.trim()) return null
        const color = normalizeHexColor(hex)
        if (getContrastRatio(color, surface) >= minRatio) return color

        const sat = hexSaturation(color)
        if (sat < 0.15) return null

        let candidate = color
        for (let i = 0; i < 8; i++) {
            candidate = normalizeHexColor(lightenColor(candidate, 28))
            if (getContrastRatio(candidate, surface) >= minRatio) return candidate
        }
        return null
    }

    return resolve(hexColor) || resolve(fallback) || normalizeHexColor(SITE_FALLBACK)
}
