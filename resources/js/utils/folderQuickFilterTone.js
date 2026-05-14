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
 * @param {string|undefined|null} textColor          Sidebar foreground color hex (e.g. '#ffffff' or '#0f172a').
 * @param {string|undefined|null} sidebarColor       Sidebar background color hex (e.g. '#5B2D7E').
 * @param {string|undefined|null} sidebarActiveBg    Brand-darkened active-row background hex (Sidebar `activeBgColor`).
 */
export function resolveQuickFilterTone(textColor, sidebarColor, sidebarActiveBg) {
    const tc = (textColor || '').toString().trim().toLowerCase()
    const isDark = tc === '#ffffff' || tc === '#fff' || tc === 'white'

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
    const selectedHex =
        normalizeHex(sidebarActiveBg) || darkenOrLighten(surfaceHex, isDark ? -0.20 : -0.08)
    // Hover is a softer darken than selected.
    const hoverHex = darkenOrLighten(surfaceHex, isDark ? -0.10 : -0.04)
    // Open-row background lives on the SIDEBAR (not the flyout). It should
    // visually merge into the flyout surface, so we use the same selected
    // hex with slight translucency so the sidebar tone bleeds through.
    const rowOpenBg = withAlpha(selectedHex, 0.85)
    // Phase 5 — unified hover intensity across the row + flyout values.
    // 0.55 alpha puts the hover squarely between idle and selected, and
    // renders identically on both the opaque sidebar surface and the
    // translucent flyout surface (which is the same brand color at 96-98%
    // opacity, so a 0.55 darken composes the same way).
    const sharedHoverBg = withAlpha(hoverHex, 0.55)

    if (isDark) {
        return {
            isDark: true,
            surface: withAlpha(surfaceHex, 0.96),
            surfaceElevated: withAlpha(elevatedSurfaceHex, 0.96),
            border: 'rgba(255, 255, 255, 0.06)',
            separator: 'rgba(255, 255, 255, 0.06)',
            rowOpenBg,
            rowHoverBg: sharedHoverBg,
            valueHoverBg: sharedHoverBg,
            valueSelectedBg: selectedHex,
            labelStrong: 'rgba(255, 255, 255, 0.96)',
            labelWeak: 'rgba(255, 255, 255, 0.65)',
            // Phase 5 — count text color is exposed explicitly so tests can
            // assert the secondary label contract instead of fishing through
            // the opacity stack.
            countLabel: 'rgba(255, 255, 255, 0.62)',
            indicatorBorder: 'rgba(255, 255, 255, 0.32)',
            indicatorActiveBg: 'rgba(255, 255, 255, 0.92)',
            indicatorActiveFg: 'rgba(20, 22, 28, 1)',
            leftGuide: 'rgba(255, 255, 255, 0.10)',
            // Layered ambient shadow tuned for darker brand surfaces.
            shadow:
                '0 1px 2px rgba(0, 0, 0, 0.30), 0 6px 18px -6px rgba(0, 0, 0, 0.45), 0 24px 60px -24px rgba(0, 0, 0, 0.55)',
            scrollbarThumb: 'rgba(255, 255, 255, 0.18)',
        }
    }

    return {
        isDark: false,
        surface: withAlpha(surfaceHex, 0.98),
        surfaceElevated: withAlpha(elevatedSurfaceHex, 0.98),
        border: 'rgba(15, 23, 42, 0.08)',
        separator: 'rgba(15, 23, 42, 0.06)',
        rowOpenBg,
        rowHoverBg: sharedHoverBg,
        valueHoverBg: sharedHoverBg,
        valueSelectedBg: selectedHex,
        labelStrong: 'rgba(15, 23, 42, 0.92)',
        labelWeak: 'rgba(71, 85, 105, 0.85)',
        countLabel: 'rgba(71, 85, 105, 0.78)',
        indicatorBorder: 'rgba(71, 85, 105, 0.45)',
        indicatorActiveBg: 'rgba(30, 41, 59, 0.95)',
        indicatorActiveFg: 'rgba(255, 255, 255, 1)',
        leftGuide: 'rgba(15, 23, 42, 0.08)',
        shadow:
            '0 1px 2px rgba(15, 23, 42, 0.06), 0 6px 18px -6px rgba(15, 23, 42, 0.12), 0 24px 60px -24px rgba(15, 23, 42, 0.18)',
        scrollbarThumb: 'rgba(15, 23, 42, 0.18)',
    }
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
