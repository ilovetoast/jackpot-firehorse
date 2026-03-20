/**
 * Full-screen loading overlay for company/brand switches that use full page navigation.
 * sessionStorage bridges the unload → next document gap; app.blade.php shows the same on load.
 */
export const WORKSPACE_SWITCHING_STORAGE_KEY = 'jackpot_workspace_switching'

const OVERLAY_ID = 'jackpot-workspace-switch-overlay'
const STYLE_ID = 'jackpot-ws-spin-style'

function overlayMarkup(kind) {
    const label =
        kind === 'brand'
            ? 'Switching brand…'
            : kind === 'company'
              ? 'Switching workspace…'
              : 'Loading…'

    return `
<div id="${OVERLAY_ID}" style="position:fixed;inset:0;z-index:2147483647;background:rgba(11,11,13,0.94);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1.25rem;font-family:ui-sans-serif,system-ui,sans-serif;">
  <div style="width:2.5rem;height:2.5rem;border:3px solid rgba(255,255,255,0.15);border-top-color:rgba(255,255,255,0.95);border-radius:50%;animation:jackpot-ws-spin 0.75s linear infinite"></div>
  <p style="color:rgba(255,255,255,0.88);font-size:0.95rem;margin:0;font-weight:500;letter-spacing:0.02em">${label}</p>
  <p style="color:rgba(255,255,255,0.45);font-size:0.75rem;margin:0">Just a moment</p>
</div>
<style id="${STYLE_ID}">@keyframes jackpot-ws-spin{to{transform:rotate(360deg)}}</style>
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
