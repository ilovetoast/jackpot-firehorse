import { useEffect } from 'react'
import { router } from '@inertiajs/react'

const HIGHLIGHT_RE = /^[a-z0-9][a-z0-9_.-]{0,63}$/
const HELP_RE = /^[a-z0-9][a-z0-9_.-]{0,127}$/
const HIGHLIGHT_MS = 4200
const STRIP_DELAY_MS = 400

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
    const q = next.searchParams.toString()
    return q ? `${next.pathname}?${q}` : next.pathname
}

/**
 * Reads `?highlight=` (optional `?help=`). Highlights `[data-help="…"]` briefly,
 * then removes both keys from the URL via Inertia replace navigation.
 */
export function useHelpHighlightFromUrl(pageUrl) {
    useEffect(() => {
        const u = parsePageUrl(pageUrl)
        if (!u) {
            return undefined
        }

        const rawHighlight = u.searchParams.get('highlight')
        const rawHelp = u.searchParams.get('help')

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

        let stripTimer
        let unhighlightTimer
        let raf = 0
        let elRef = null

        const run = () => {
            const el = document.querySelector(`[data-help="${rawHighlight}"]`)
            if (!el) {
                stripTimer = window.setTimeout(() => {
                    router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
                }, STRIP_DELAY_MS)
                return
            }
            elRef = el
            el.classList.add('jp-help-highlight')
            try {
                el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' })
            } catch {
                el.scrollIntoView(true)
            }
            stripTimer = window.setTimeout(() => {
                router.get(stripHelpParams(u), {}, { replace: true, preserveState: true, preserveScroll: true })
            }, STRIP_DELAY_MS)
            unhighlightTimer = window.setTimeout(() => {
                el.classList.remove('jp-help-highlight')
            }, HIGHLIGHT_MS)
        }

        raf = window.requestAnimationFrame(() => run())

        return () => {
            window.cancelAnimationFrame(raf)
            if (stripTimer) window.clearTimeout(stripTimer)
            if (unhighlightTimer) window.clearTimeout(unhighlightTimer)
            if (elRef) {
                elRef.classList.remove('jp-help-highlight')
            }
        }
    }, [pageUrl])
}
