import { createPortal } from 'react-dom'
import { useCallback, useEffect, useLayoutEffect, useRef } from 'react'

const AUTO_DISMISS_MS = 5500

/**
 * Full-page dim + system-violet ring on `[data-help="…"]`, optional floating label.
 * Target stays above the backdrop so it remains clickable; backdrop click / Escape / timeout dismiss.
 */
export default function HelpGuidedHighlightOverlay({ session, onDismiss }) {
    const targetRef = useRef(null)
    const calloutRef = useRef(null)
    const dismissTimerRef = useRef(null)

    const clearDismissTimer = useCallback(() => {
        if (dismissTimerRef.current !== null) {
            window.clearTimeout(dismissTimerRef.current)
            dismissTimerRef.current = null
        }
    }, [])

    const layoutCallout = useCallback(() => {
        const el = targetRef.current
        const tip = calloutRef.current
        if (!el) {
            return
        }
        const rect = el.getBoundingClientRect()
        const margin = 8
        const vw = window.innerWidth
        const vh = window.innerHeight

        if (tip) {
            const tipRect = tip.getBoundingClientRect()
            let top = rect.bottom + margin
            let left = rect.left + rect.width / 2 - tipRect.width / 2
            if (top + tipRect.height > vh - margin && rect.top > tipRect.height + margin * 2) {
                top = rect.top - tipRect.height - margin
            }
            left = Math.min(Math.max(margin, left), vw - tipRect.width - margin)
            tip.style.top = `${top}px`
            tip.style.left = `${left}px`
        }
    }, [])

    const dismiss = useCallback(() => {
        clearDismissTimer()
        const el = targetRef.current
        if (el) {
            el.classList.remove('jp-help-guided-target')
        }
        targetRef.current = null
        onDismiss()
    }, [clearDismissTimer, onDismiss])

    useLayoutEffect(() => {
        if (!session) {
            return undefined
        }

        const el = document.querySelector(`[data-help="${session.selector}"]`)
        if (!el) {
            onDismiss()
            return undefined
        }

        targetRef.current = el
        el.classList.add('jp-help-guided-target')

        const reduceMotion =
            typeof window !== 'undefined' &&
            window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches

        try {
            el.scrollIntoView({
                block: 'center',
                inline: 'nearest',
                behavior: reduceMotion ? 'auto' : 'smooth',
            })
        } catch {
            el.scrollIntoView(true)
        }

        return () => {
            if (targetRef.current) {
                targetRef.current.classList.remove('jp-help-guided-target')
                targetRef.current = null
            }
        }
    }, [session, onDismiss])

    useEffect(() => {
        if (!session) {
            return undefined
        }

        const onEsc = (e) => {
            if (e.key === 'Escape') {
                dismiss()
            }
        }

        const onScrollOrResize = () => {
            window.requestAnimationFrame(() => layoutCallout())
        }

        dismissTimerRef.current = window.setTimeout(() => dismiss(), AUTO_DISMISS_MS)
        window.addEventListener('keydown', onEsc)
        window.addEventListener('resize', onScrollOrResize)
        window.addEventListener('scroll', onScrollOrResize, true)

        window.requestAnimationFrame(() => layoutCallout())

        return () => {
            clearDismissTimer()
            window.removeEventListener('keydown', onEsc)
            window.removeEventListener('resize', onScrollOrResize)
            window.removeEventListener('scroll', onScrollOrResize, true)
        }
    }, [session, dismiss, layoutCallout, clearDismissTimer])

    useEffect(() => {
        if (!session?.label) {
            return undefined
        }
        window.requestAnimationFrame(() => layoutCallout())
    }, [session?.label, layoutCallout])

    if (!session) {
        return null
    }

    const backdrop = (
        <button
            type="button"
            className="jp-help-guided-backdrop"
            onClick={dismiss}
            aria-label="Dismiss highlight"
        />
    )

    const callout =
        session.label && String(session.label).trim() !== '' ? (
            <div
                ref={calloutRef}
                className="jp-help-guided-callout"
                role="status"
                aria-live="polite"
            >
                {session.label}
            </div>
        ) : null

    return createPortal(
        <>
            {backdrop}
            {callout}
        </>,
        document.body
    )
}
