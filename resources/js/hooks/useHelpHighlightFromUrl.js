import { useCallback, useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'

const HIGHLIGHT_RE = /^[a-z0-9][a-z0-9_.-]{0,63}$/
const HELP_RE = /^[a-z0-9][a-z0-9_.-]{0,127}$/
const LABEL_MAX = 120

function parsePageUrl(pageUrl) {
    if (typeof window === 'undefined' || !pageUrl) {
        return null
    }
    try {
        return new URL(pageUrl.startsWith('http') ? pageUrl : pageUrl, window.location.origin)
    } catch {
        return null
    }
}

function stripHelpParams(urlObj) {
    const next = new URL(urlObj.toString())
    next.searchParams.delete('help')
    next.searchParams.delete('highlight')
    next.searchParams.delete('highlight_label')
    const q = next.searchParams.toString()
    return q ? `${next.pathname}?${q}` : next.pathname
}

function sanitizeHighlightLabel(raw) {
    if (raw == null || typeof raw !== 'string') {
        return null
    }
    try {
        const s = decodeURIComponent(raw).trim().slice(0, LABEL_MAX)
        if (!s) {
            return null
        }
        return s.replace(/\0/g, '')
    } catch {
        return null
    }
}

/**
 * Reads `?highlight=` (optional `?help=`, `?highlight_label=`).
 * Strips those params immediately via Inertia replace, then drives {@link HelpGuidedHighlightOverlay}.
 */
export function useHelpHighlightFromUrl(pageUrl) {
    const [session, setSession] = useState(null)
    const dismiss = useCallback(() => setSession(null), [])

    useEffect(() => {
        if (!session) {
            return
        }
        const u = parsePageUrl(pageUrl)
        if (!u || u.pathname !== session.startPathname) {
            setSession(null)
        }
    }, [pageUrl, session])

    useEffect(() => {
        const u = parsePageUrl(pageUrl)
        if (!u) {
            return undefined
        }

        const rawHelp = u.searchParams.get('help')
        const rawHighlight = u.searchParams.get('highlight')

        if (rawHelp && !HELP_RE.test(rawHelp)) {
            router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            return undefined
        }
        if (rawHighlight && !HIGHLIGHT_RE.test(rawHighlight)) {
            router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            return undefined
        }
        if (!rawHighlight || !HIGHLIGHT_RE.test(rawHighlight)) {
            return undefined
        }

        const label = sanitizeHighlightLabel(u.searchParams.get('highlight_label'))

        let cancelled = false
        let raf = 0
        raf = window.requestAnimationFrame(() => {
            if (cancelled) {
                return
            }
            const el = document.querySelector(`[data-help="${rawHighlight}"]`)
            const path = stripHelpParams(u)
            router.get(path, {}, { replace: true, preserveState: true, preserveScroll: true })
            if (!el) {
                return
            }
            setSession({
                id: Date.now(),
                selector: rawHighlight,
                label,
                startPathname: u.pathname,
            })
        })

        return () => {
            cancelled = true
            window.cancelAnimationFrame(raf)
        }
    }, [pageUrl])

    return [session, dismiss]
}
