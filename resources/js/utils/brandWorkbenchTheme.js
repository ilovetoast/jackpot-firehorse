import {
    normalizeHexColor,
    getLuminance,
    getWorkspacePrimaryActionButtonColors,
    getSolidFillButtonForegroundHex,
    ensureAccentContrastOnWhite,
    darkenColor,
    hexToRgba,
} from './colorUtils'
import { JACKPOT_VIOLET } from '../components/brand-workspace/brandWorkspaceTokens'

/**
 * Pick a chromatic base for brand workbench chrome (Insights, Manage, Brand settings).
 * Skips near-white primaries and tries accent → secondary → company primary.
 */
export function pickBrandWorkbenchPaletteBase(brand, company) {
    const chain = [brand?.primary_color, brand?.accent_color, brand?.secondary_color, company?.primary_color]
    for (const raw of chain) {
        if (!raw || typeof raw !== 'string') continue
        const hex = normalizeHexColor(raw)
        const lum = getLuminance(hex)
        if (lum > 0.93) continue
        if (lum < 0.02) continue
        return hex
    }
    return JACKPOT_VIOLET
}

/**
 * CSS variables + imperative tokens for brand-tinted workbench (replaces Jackpot violet in scoped UI).
 */
export function buildBrandWorkbenchChromePackage(brand, company) {
    const base = pickBrandWorkbenchPaletteBase(brand, company)
    const synthetic = {
        ...(brand && typeof brand === 'object' ? brand : {}),
        primary_color: base,
        workspace_button_style: 'primary',
        settings: { ...(brand?.settings && typeof brand.settings === 'object' ? brand.settings : {}), button_style: 'primary' },
    }
    const { resting: accentFill, hover: accentHover } = getWorkspacePrimaryActionButtonColors(synthetic)
    const onAccent = getSolidFillButtonForegroundHex(accentFill)
    const onAccentHover = getSolidFillButtonForegroundHex(accentHover)
    const link = ensureAccentContrastOnWhite(base, 4.5)
    const linkHover = darkenColor(link, 14)

    const soft = (a) => hexToRgba(base, a)

    const vars = {
        '--wb-accent': accentFill,
        '--wb-accent-hover': accentHover,
        '--wb-on-accent': onAccent,
        '--wb-on-accent-hover': onAccentHover,
        '--wb-link': link,
        '--wb-link-hover': linkHover,
        '--wb-ring': hexToRgba(accentFill, 0.45),
        '--wb-soft-bg': soft(0.1),
        '--wb-soft-bg-strong': soft(0.14),
        '--wb-soft-border': soft(0.24),
        '--wb-muted-text': darkenColor(link, 6),
    }

    return {
        vars,
        accentFill,
        accentHover,
        onAccent,
        onAccentHover,
        linkHex: link,
        linkHoverHex: linkHover,
        paletteBase: base,
    }
}
