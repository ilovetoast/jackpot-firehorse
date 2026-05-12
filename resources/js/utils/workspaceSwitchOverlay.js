/**
 * Full-screen loading overlay for company/brand switches that use full page navigation.
 * sessionStorage bridges the unload → next document gap; app.blade.php shows the same on load.
 */
export const WORKSPACE_SWITCHING_STORAGE_KEY = 'jackpot_workspace_switching'

const OVERLAY_ID = 'jackpot-workspace-switch-overlay'
const STYLE_ID = 'jackpot-ws-slot-style'

/** Same asset paths as {@see ../Components/SlotReelLoader.jsx} — white SVGs, inverted on dark overlay. */
const SLOT_SVGS = {
    cherry: '/jp-parts/cherry-slot.svg',
    seven: '/jp-parts/seven-slot.svg',
    diamond: '/jp-parts/diamond-slot.svg',
}

function buildReelStrip(symbols) {
    const doubled = [...symbols, ...symbols]
    return doubled
        .map(
            (src) =>
                `<div class="jp-ws-slot-cell"><img src="${src}" alt="" width="40" height="40" decoding="async" draggable="false"></div>`,
        )
        .join('')
}

function overlayMarkup(kind) {
    const label =
        kind === 'brand'
            ? 'Switching brand…'
            : kind === 'company'
              ? 'Switching workspace…'
              : 'Loading…'

    const r1 = buildReelStrip([SLOT_SVGS.cherry, SLOT_SVGS.seven, SLOT_SVGS.diamond, SLOT_SVGS.cherry, SLOT_SVGS.seven, SLOT_SVGS.diamond])
    const r2 = buildReelStrip([SLOT_SVGS.seven, SLOT_SVGS.diamond, SLOT_SVGS.cherry, SLOT_SVGS.seven, SLOT_SVGS.diamond, SLOT_SVGS.cherry])
    const r3 = buildReelStrip([SLOT_SVGS.diamond, SLOT_SVGS.cherry, SLOT_SVGS.seven, SLOT_SVGS.diamond, SLOT_SVGS.cherry, SLOT_SVGS.seven])

    return `
<div id="${OVERLAY_ID}" style="position:fixed;inset:0;z-index:2147483647;background:rgba(11,11,13,0.94);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1.35rem;font-family:ui-sans-serif,system-ui,sans-serif;">
  <div role="status" aria-live="polite" aria-busy="true" style="display:flex;align-items:center;justify-content:center;gap:10px;height:64px">
    <div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--a">${r1}</div></div>
    <div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--b">${r2}</div></div>
    <div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--c">${r3}</div></div>
  </div>
  <p style="color:rgba(255,255,255,0.88);font-size:0.95rem;margin:0;font-weight:500;letter-spacing:0.02em">${label}</p>
  <p style="color:rgba(255,255,255,0.45);font-size:0.75rem;margin:0">Just a moment</p>
</div>
<style id="${STYLE_ID}">
@keyframes jp-ws-reel-spin{from{transform:translateY(0)}to{transform:translateY(-50%)}}
.jp-ws-reel-window{overflow:hidden;width:52px;height:56px;border-radius:8px;background:#fff;box-shadow:0 4px 28px rgba(0,0,0,0.4)}
.jp-ws-reel-strip{display:flex;flex-direction:column;width:100%;animation:jp-ws-reel-spin linear infinite;will-change:transform}
.jp-ws-reel-strip--a{animation-duration:1.45s}
.jp-ws-reel-strip--b{animation-duration:1.9s}
.jp-ws-reel-strip--c{animation-duration:1.65s}
.jp-ws-slot-cell{flex-shrink:0;height:28px;display:flex;align-items:center;justify-content:center;padding:12% 14%;box-sizing:border-box}
.jp-ws-slot-cell img{height:100%;width:100%;object-fit:contain;filter:invert(1);pointer-events:none;-webkit-user-select:none;user-select:none}
@media (prefers-reduced-motion:reduce){.jp-ws-reel-strip{animation:none!important}}
</style>
`
}

/**
 * @param {'company'|'brand'} kind
 */
export function showWorkspaceSwitchingOverlay(kind = 'company') {
    try {
        sessionStorage.setItem(WORKSPACE_SWITCHING_STORAGE_KEY, kind)
    } catch {
        /* ignore */
    }

    if (typeof document === 'undefined') return

    document.getElementById(OVERLAY_ID)?.remove()
    const existingStyle = document.getElementById(STYLE_ID)
    if (existingStyle) existingStyle.remove()

    const wrap = document.createElement('div')
    wrap.innerHTML = overlayMarkup(kind).trim()
    const overlay = wrap.querySelector(`#${OVERLAY_ID}`)
    const style = wrap.querySelector(`#${STYLE_ID}`)
    if (overlay) {
        document.body.appendChild(overlay)
    }
    if (style) {
        document.head.appendChild(style)
    }
    try {
        Object.values(SLOT_SVGS).forEach((src) => {
            const im = new Image()
            im.decoding = 'async'
            im.src = src
        })
    } catch {
        /* ignore */
    }
}

export function removeWorkspaceSwitchingOverlay() {
    try {
        sessionStorage.removeItem(WORKSPACE_SWITCHING_STORAGE_KEY)
    } catch {
        /* ignore */
    }
    if (typeof document === 'undefined') return
    document.getElementById(OVERLAY_ID)?.remove()
    document.getElementById(STYLE_ID)?.remove()
}
