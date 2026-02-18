/**
 * Observability v1: Client-side performance tracking.
 * Captures TTFB, DOMContentLoaded, Load event, total load time.
 * Throttled to 1 per session. Sends to /app/admin/performance/client-metric.
 */
const SESSION_KEY = 'jackpot_performance_metric_sent'
const imageLoadTimes = []

export function initPerformanceTracking() {
    if (typeof window === 'undefined') return
    if (!window.__performanceMetricsEnabled) return
    if (sessionStorage.getItem(SESSION_KEY)) return

    const send = () => {
        try {
            const nav = performance.getEntriesByType?.('navigation')?.[0]
            const timing = performance.timing

            let ttfbMs = null
            let domContentLoadedMs = null
            let loadEventMs = null
            let totalLoadMs = null

            if (nav && 'responseStart' in nav) {
                ttfbMs = Math.round(nav.responseStart)
                domContentLoadedMs = nav.domContentLoadedEventEnd
                    ? Math.round(nav.domContentLoadedEventEnd - nav.fetchStart)
                    : null
                loadEventMs = nav.loadEventEnd ? Math.round(nav.loadEventEnd - nav.fetchStart) : null
                totalLoadMs = nav.loadEventEnd ? Math.round(nav.loadEventEnd - nav.startTime) : null
            } else if (timing) {
                ttfbMs = timing.responseStart > 0 ? Math.round(timing.responseStart - timing.navigationStart) : null
                domContentLoadedMs =
                    timing.domContentLoadedEventEnd > 0
                        ? Math.round(timing.domContentLoadedEventEnd - timing.navigationStart)
                        : null
                loadEventMs =
                    timing.loadEventEnd > 0 ? Math.round(timing.loadEventEnd - timing.navigationStart) : null
                totalLoadMs =
                    timing.loadEventEnd > 0 ? Math.round(timing.loadEventEnd - timing.navigationStart) : null
            }

            const path = window.location.pathname || null
            const url = window.location.href || ''

            const avgImageMs =
                imageLoadTimes.length > 0
                    ? Math.round(
                          imageLoadTimes.reduce((a, b) => a + b, 0) / imageLoadTimes.length
                      )
                    : null

            const payload = {
                url,
                path,
                ttfb_ms: ttfbMs,
                dom_content_loaded_ms: domContentLoadedMs,
                load_event_ms: loadEventMs,
                total_load_ms: totalLoadMs,
                avg_image_load_ms: avgImageMs,
                image_count: imageLoadTimes.length || null,
            }

            fetch('/app/admin/performance/client-metric', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    ...(document.querySelector('meta[name="csrf-token"]')?.content && {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    }),
                },
                body: JSON.stringify(payload),
                keepalive: true,
            })
                .then((r) => {
                    if (r.ok) sessionStorage.setItem(SESSION_KEY, '1')
                })
                .catch(() => {})
        } catch (_) {}
    }

    const doSend = () => {
        setTimeout(send, 2000)
    }
    if (document.readyState === 'complete') {
        doSend()
    } else {
        window.addEventListener('load', doSend, { once: true })
    }
}

/**
 * Track image load time. Call from img onLoad: onLoad={(e) => trackImageLoad(e)}
 * Pushes to aggregation for the page load metric. Uses PerformanceResourceTiming.
 * Returns load time in ms.
 */
export function trackImageLoad(e) {
    if (typeof window === 'undefined' || !e?.target) return null
    const img = e.target
    const src = img.currentSrc || img.src
    if (!src) return null
    try {
        const entries = performance.getEntriesByType?.('resource') || []
        const entry = entries.find((r) => r.name === src)
        if (entry && 'duration' in entry) {
            const ms = Math.round(entry.duration)
            imageLoadTimes.push(ms)
            return ms
        }
    } catch (_) {}
    return null
}
