/**
 * Phase 4.2 / 4.4 / 5 — derive a brand-aware contextual tonal palette for the
 * Folder Quick Filter row + flyout from the sidebar's foreground color, the
 * sidebar surface color, and the brand's active-row tone.
 *
 * Design intent: the flyout reads as a continuation of the sidebar — same
 * brand surface, same darkened-highlight pattern as the active folder row.
 * Phase 5 unifies hover intensity across the row and the flyout value rows
 * so the two surfaces feel like one interaction system. Both surfaces (the
 * sidebar and the slightly translucent flyout) sit over the same brand
 * color, so the same alpha-darkened hover tone renders identically on
 * both.
 *
 * @param {string|undefined|null} textColor          Sidebar row foreground (e.g. '#ffffff', '#fff', or 'rgba(255,255,255,0.88)').
 * @param {string|undefined|null} sidebarColor       Sidebar background color hex (e.g. '#5B2D7E').
 * @param {string|undefined|null} sidebarActiveBg    Brand-darkened active-row background hex (Sidebar `activeBgColor`).
 * @param {string|undefined|null} brandAccentHex     Workspace / brand primary (e.g. button color). When set, selected
 *                                                    flyout rows + multiselect indicators tint from this instead of slate.
 * @param {string|undefined|null} sidebarBackdropCss When set (cinematic workspace sidebar), the flyout uses this same
 *                                                    `background` as the sidebar so the panel matches brand theme.
 */
export function resolveQuickFilterTone(
    textColor,
    sidebarColor,
    sidebarActiveBg,
    brandAccentHex = null,
    sidebarBackdropCss = null
) {
    const tc = (textColor || '').toString().trim().toLowerCase()
    const backdrop =
        typeof sidebarBackdropCss === 'string' && sidebarBackdropCss.trim() !== ''
            ? sidebarBackdropCss.trim()
            : null
    // Sidebar row foreground is often rgba(255,255,255,…) rather than literal "#fff"; treat any
    // sufficiently light foreground as "dark rail" so the flyout matches the workspace sidebar.
    const isDark = isLightOnDarkSidebarForeground(tc)

    // Surface colors:
    //   - Prefer the sidebar's actual surface color so the flyout reads as
    //     "from the same family" as the sidebar.
    //   - Fall back to a neutral slate slab for callers that don't pass one.
    const surfaceHex = normalizeHex(sidebarColor) || (isDark ? '#1e2026' : '#ffffff')
    const elevatedSurfaceHex = darkenOrLighten(surfaceHex, isDark ? -0.06 : 0.04)
    // Selected/open background. Prefer the brand's darkened-active tone (so
    // the flyout's selected row matches the sidebar's active folder row).
    // Otherwise compute a small darken on the surface — selection is always
    // a *darken*, never a saturate, to match the brand visual language.
    const anchorHex =
        normalizeHex(sidebarActiveBg) || darkenOrLighten(surfaceHex, isDark ? -0.20 : -0.08)
    const brandHex = normalizeHex(brandAccentHex)
    const valueSelectedBg = brandHex
        ? isDark
            ? withAlpha(brandHex, 0.44)
            : withAlpha(brandHex, 0.14)
        : anchorHex
    const rowOpenBg = withAlpha(anchorHex, 0.85)
    // Phase 5 — unified hover intensity across the row + flyout values.
    // 0.55 alpha puts the hover squarely between idle and selected, and
    // renders identically on both the opaque sidebar surface and the
    // translucent flyout surface (which is the same brand color at 96-98%
    // opacity, so a 0.55 darken composes the same way).
    const hoverHex = anchorHex
    const sharedHoverBg = withAlpha(hoverHex, 0.55)

    const flyoutBackdrop =
        backdrop != null
            ? {
                  flyoutBackground: backdrop,
                  flyoutBackgroundColor: '#0B0B0D',
              }
            : {}

    if (isDark) {
        return {
            isDark: true,
            surface: withAlpha(surfaceHex, 0.96),
            surfaceElevated: backdrop
                ? 'rgba(255, 255, 255, 0.08)'
                : withAlpha(elevatedSurfaceHex, 0.96),
            border: 'rgba(255, 255, 255, 0.06)',
            separator: 'rgba(255, 255, 255, 0.06)',
            rowOpenBg,
            rowHoverBg: sharedHoverBg,
            valueHoverBg: sharedHoverBg,
            valueSelectedBg,
            labelStrong: 'rgba(255, 255, 255, 0.96)',
            labelWeak: 'rgba(255, 255, 255, 0.65)',
            // Phase 5 — count text color is exposed explicitly so tests can
            // assert the secondary label contract instead of fishing through
            // the opacity stack.
            countLabel: 'rgba(255, 255, 255, 0.62)',
            indicatorBorder: 'rgba(255, 255, 255, 0.32)',
            indicatorActiveBg: brandHex ? withAlpha(brandHex, 0.75) : 'rgba(255, 255, 255, 0.92)',
            indicatorActiveFg: brandHex ? '#ffffff' : 'rgba(20, 22, 28, 1)',
            leftGuide: 'rgba(255, 255, 255, 0.10)',
            // Layered ambient shadow tuned for darker brand surfaces.
            shadow:
                '0 1px 2px rgba(0, 0, 0, 0.30), 0 6px 18px -6px rgba(0, 0, 0, 0.45), 0 24px 60px -24px rgba(0, 0, 0, 0.55)',
            scrollbarThumb: 'rgba(255, 255, 255, 0.18)',
            ...flyoutBackdrop,
        }
    }

    return {
        isDark: false,
        surface: withAlpha(surfaceHex, 0.98),
        surfaceElevated: backdrop
            ? 'rgba(15, 23, 42, 0.06)'
            : withAlpha(elevatedSurfaceHex, 0.98),
        border: 'rgba(15, 23, 42, 0.08)',
        separator: 'rgba(15, 23, 42, 0.06)',
        rowOpenBg,
        rowHoverBg: sharedHoverBg,
        valueHoverBg: sharedHoverBg,
        valueSelectedBg,
        labelStrong: 'rgba(15, 23, 42, 0.92)',
        labelWeak: 'rgba(71, 85, 105, 0.85)',
        countLabel: 'rgba(71, 85, 105, 0.78)',
        indicatorBorder: 'rgba(71, 85, 105, 0.45)',
        indicatorActiveBg: brandHex ? withAlpha(brandHex, 0.88) : 'rgba(30, 41, 59, 0.95)',
        indicatorActiveFg: brandHex ? '#ffffff' : 'rgba(255, 255, 255, 1)',
        leftGuide: 'rgba(15, 23, 42, 0.08)',
        shadow:
            '0 1px 2px rgba(15, 23, 42, 0.06), 0 6px 18px -6px rgba(15, 23, 42, 0.12), 0 24px 60px -24px rgba(15, 23, 42, 0.18)',
        scrollbarThumb: 'rgba(15, 23, 42, 0.18)',
        ...flyoutBackdrop,
    }
}

/**
 * True when the sidebar passes a light foreground (white / near-white), including common
 * rgba() forms from theme tokens — not only exact "#ffffff".
 */
function isLightOnDarkSidebarForeground(normalizedLower) {
    const tc = normalizedLower
    if (tc === '#ffffff' || tc === '#fff' || tc === 'white') {
        return true
    }
    const rgba = tc.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)$/)
    if (rgba) {
        const r = Number(rgba[1])
        const g = Number(rgba[2])
        const b = Number(rgba[3])
        const a = rgba[4] !== undefined ? Number(rgba[4]) : 1
        if (!Number.isFinite(a) || a < 0.12) {
            return false
        }
        const lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
        return lum >= 0.72
    }
    const hex = normalizeHex(tc)
    if (hex) {
        const rgb = hexToRgb(hex)
        if (!rgb) {
            return false
        }
        const lum = (0.2126 * rgb.r + 0.7152 * rgb.g + 0.0722 * rgb.b) / 255
        return lum >= 0.72
    }
    return false
}

/** Normalize "#abc"/"#aabbcc" to "#aabbcc". Returns null on bad input. */
function normalizeHex(value) {
    if (!value || typeof value !== 'string') return null
    let s = value.trim().toLowerCase()
    if (!s.startsWith('#')) return null
    if (s.length === 4) {
        s = `#${s[1]}${s[1]}${s[2]}${s[2]}${s[3]}${s[3]}`
    }
    return /^#[0-9a-f]{6}$/.test(s) ? s : null
}

/** Convert hex → {r,g,b}. Returns null on bad input. */
function hexToRgb(hex) {
    const n = normalizeHex(hex)
    if (!n) return null
    return {
        r: parseInt(n.slice(1, 3), 16),
        g: parseInt(n.slice(3, 5), 16),
        b: parseInt(n.slice(5, 7), 16),
    }
}

/** Hex + amount in [-1..1]. Negative darkens, positive lightens. */
function darkenOrLighten(hex, amount) {
    const rgb = hexToRgb(hex)
    if (!rgb) return hex
    const k = Math.max(-1, Math.min(1, amount))
    const apply = (c) => {
        // Lerp toward 0 (darken) or 255 (lighten).
        const target = k < 0 ? 0 : 255
        return Math.round(c + (target - c) * Math.abs(k))
    }
    const r = apply(rgb.r)
    const g = apply(rgb.g)
    const b = apply(rgb.b)
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b
        .toString(16)
        .padStart(2, '0')}`
}

/** Hex + alpha → rgba() string. Falls back to the input if hex is invalid. */
function withAlpha(hex, alpha) {
    const rgb = hexToRgb(hex)
    if (!rgb) return hex
    const a = Math.max(0, Math.min(1, alpha))
    return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${a})`
}
