/**
 * Reads the audio playback feature flags shared by Laravel via Inertia
 * (`pageProps.audioPlayback`). Centralised so the registry / hook /
 * AudioCardVisual all reach for the same source of truth without each
 * needing access to Inertia's React context.
 *
 * Inertia rehydrates `window.__inertia` synchronously on first render so
 * this is safe to call at module load too — but we always read fresh so
 * that flipping the flag during a session (e.g. via `app.usePage` updates)
 * is picked up on the next decision.
 */

function readPageProps() {
    if (typeof window === 'undefined') return null
    const inertia = window.__inertia
    if (inertia && typeof inertia === 'object') {
        if (inertia.page && typeof inertia.page === 'object') return inertia.page
    }
    // Fallback: Inertia stores the initial page on a `data-page` attribute
    // of the app root for SSR hydration. Probe it once.
    try {
        const el = document.getElementById('app')
        if (el && el.dataset && el.dataset.page) {
            return JSON.parse(el.dataset.page)
        }
    } catch (_) {
        // Ignore parse failures; flag will fall back to false.
    }
    return null
}

export function isLiveAudioAnalyserEnabled() {
    const page = readPageProps()
    const flag = page?.props?.audioPlayback?.live_analyser_enabled
    return flag === true
}
