import { useCallback, useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'

/** Exported for HelpLauncher URL + session payloads (matches server-side data-help tokens). */
export const HELP_HIGHLIGHT_TOKEN_RE = /^[a-z0-9][a-z0-9_.-]{0,63}$/
const HIGHLIGHT_RE = HELP_HIGHLIGHT_TOKEN_RE
const HELP_RE = /^[a-z0-9][a-z0-9_.-]{0,127}$/
const LABEL_MAX = 120

const SHOWME_STORAGE_KEY = 'jp_help_showme_v1'
const SHOWME_STORAGE_TTL_MS = 120000

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
    next.searchParams.delete('highlight_fb')
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

function readShowMeStorage(helpKey) {
    try {
        const raw = sessionStorage.getItem(SHOWME_STORAGE_KEY)
        if (!raw) {
            return null
        }
        const o = JSON.parse(raw)
        if (!o || o.helpKey !== helpKey) {
            return null
        }
        if (typeof o.savedAt !== 'number' || Date.now() - o.savedAt > SHOWME_STORAGE_TTL_MS) {
            sessionStorage.removeItem(SHOWME_STORAGE_KEY)
            return null
        }
        return o
    } catch {
        return null
    }
}

function clearShowMeStorage() {
    try {
        sessionStorage.removeItem(SHOWME_STORAGE_KEY)
    } catch {
        /* ignore */
    }
}

function queryHelpEl(token) {
    if (!token || !HIGHLIGHT_RE.test(token)) {
        return null
    }
    return document.querySelector(`[data-help="${token}"]`)
}

/**
 * Reads `?highlight=` (optional `?help=`, `?highlight_label=`, `?highlight_fb=`).
 * Strips those params via Inertia replace, then drives {@link HelpGuidedHighlightOverlay}
 * or a missing-target notice when the control is not on screen.
 */
export function useHelpHighlightFromUrl(pageUrl) {
    const [highlightSession, setHighlightSession] = useState(null)
    const [missingSession, setMissingSession] = useState(null)
    const dismissHighlight = useCallback(() => setHighlightSession(null), [])
    const dismissMissing = useCallback(() => setMissingSession(null), [])

    useEffect(() => {
        if (!highlightSession) {
            return
        }
        const u = parsePageUrl(pageUrl)
        if (!u || u.pathname !== highlightSession.startPathname) {
            setHighlightSession(null)
        }
    }, [pageUrl, highlightSession])

    useEffect(() => {
        if (!missingSession) {
            return
        }
        const u = parsePageUrl(pageUrl)
        if (!u || u.pathname !== missingSession.startPathname) {
            setMissingSession(null)
        }
    }, [pageUrl, missingSession])

    useEffect(() => {
        const u = parsePageUrl(pageUrl)
        if (!u) {
            return undefined
        }

        const rawHelp = u.searchParams.get('help')
        const rawHighlight = u.searchParams.get('highlight')
        const rawHighlightFb = u.searchParams.get('highlight_fb')

        if (rawHelp && !HELP_RE.test(rawHelp)) {
            router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            return undefined
        }
        if (rawHighlight && !HIGHLIGHT_RE.test(rawHighlight)) {
            router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            return undefined
        }
        if (rawHighlightFb && !HIGHLIGHT_RE.test(rawHighlightFb)) {
            router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            return undefined
        }
        if (!rawHighlight || !HIGHLIGHT_RE.test(rawHighlight)) {
            return undefined
        }

        const label = sanitizeHighlightLabel(u.searchParams.get('highlight_label'))
        const storage = rawHelp && HELP_RE.test(rawHelp) ? readShowMeStorage(rawHelp) : null

        const path = stripHelpParams(u)
        let cancelled = false
        let raf = 0

        raf = window.requestAnimationFrame(() => {
            if (cancelled) {
                return
            }

            router.get(path, {}, { replace: true, preserveState: true, preserveScroll: true })

            const tryPrimary = queryHelpEl(rawHighlight)
            if (tryPrimary) {
                clearShowMeStorage()
                setHighlightSession({
                    id: Date.now(),
                    selector: rawHighlight,
                    label,
                    startPathname: u.pathname,
                })
                return
            }

            const fbToken =
                (rawHighlightFb && HIGHLIGHT_RE.test(rawHighlightFb) ? rawHighlightFb : null) ||
                (storage?.fallbackSelector && HIGHLIGHT_RE.test(storage.fallbackSelector)
                    ? storage.fallbackSelector
                    : null)

            if (fbToken) {
                const tryFb = queryHelpEl(fbToken)
                if (tryFb) {
                    clearShowMeStorage()
                    const fbLabel =
                        typeof storage?.fallbackLabel === 'string' && storage.fallbackLabel.trim() !== ''
                            ? storage.fallbackLabel.trim().slice(0, LABEL_MAX)
                            : label
                    setHighlightSession({
                        id: Date.now(),
                        selector: fbToken,
                        label: fbLabel,
                        startPathname: u.pathname,
                    })
                    return
                }
            }

            clearShowMeStorage()
            const title =
                typeof storage?.missingTitle === 'string' && storage.missingTitle.trim() !== ''
                    ? storage.missingTitle.trim()
                    : 'Couldn’t show that spot'
            const message =
                typeof storage?.missingMessage === 'string' && storage.missingMessage.trim() !== ''
                    ? storage.missingMessage.trim()
                    : 'That control isn’t available on the current screen. Follow the help steps, then try Show me again.'
            const ctaLabel =
                typeof storage?.missingCtaLabel === 'string' && storage.missingCtaLabel.trim() !== ''
                    ? storage.missingCtaLabel.trim().slice(0, 120)
                    : null
            const ctaUrl =
                typeof storage?.missingCtaUrl === 'string' && storage.missingCtaUrl.trim() !== ''
                    ? storage.missingCtaUrl.trim()
                    : null

            setMissingSession({
                id: Date.now(),
                title,
                message,
                ctaLabel,
                ctaUrl,
                startPathname: u.pathname,
            })
        })

        return () => {
            cancelled = true
            window.cancelAnimationFrame(raf)
        }
    }, [pageUrl])

    return [highlightSession, dismissHighlight, missingSession, dismissMissing]
}

export { SHOWME_STORAGE_KEY, SHOWME_STORAGE_TTL_MS }
