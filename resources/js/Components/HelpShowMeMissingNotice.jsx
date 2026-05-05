import { createPortal } from 'react-dom'
import { useCallback, useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'

/**
 * Shown when Help “Show me” navigates but the primary (and optional fallback) data-help target is missing.
 * Uses the same system-violet accent as guided highlights — not brand theme colors.
 */
export default function HelpShowMeMissingNotice({ session, onDismiss }) {
    const panelRef = useRef(null)

    const dismiss = useCallback(() => {
        onDismiss()
    }, [onDismiss])

    useEffect(() => {
        if (!session) {
            return undefined
        }
        const onEsc = (e) => {
            if (e.key === 'Escape') {
                dismiss()
            }
        }
        window.addEventListener('keydown', onEsc)
        return () => window.removeEventListener('keydown', onEsc)
    }, [session, dismiss])

    if (!session) {
        return null
    }

    const title = session.title || 'Couldn’t show that spot'
    const message =
        session.message ||
        'That control isn’t on this screen yet. Try the steps in the help topic, then use Show me again.'
    const ctaLabel = session.ctaLabel
    const ctaUrl = session.ctaUrl

    const onCta = () => {
        if (ctaUrl && typeof ctaUrl === 'string' && ctaUrl.trim() !== '') {
            dismiss()
            router.visit(ctaUrl)
        }
    }

    const backdrop = (
        <button
            type="button"
            className="jp-help-guided-backdrop"
            onClick={dismiss}
            aria-label="Dismiss"
        />
    )

    const panel = (
        <div
            ref={panelRef}
            className="jp-help-showme-missing-panel fixed left-1/2 top-1/2 z-[10055] w-[min(100%-2rem,22rem)] -translate-x-1/2 -translate-y-1/2 rounded-xl border border-indigo-200 bg-white p-4 shadow-xl outline-none"
            style={{
                boxShadow:
                    '0 25px 50px -12px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(99, 102, 241, 0.12)',
            }}
            role="dialog"
            aria-modal="true"
            aria-labelledby="jp-help-showme-missing-title"
        >
            <h2 id="jp-help-showme-missing-title" className="text-sm font-semibold text-slate-900">
                {title}
            </h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-600">{message}</p>
            <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    onClick={dismiss}
                    className="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50"
                >
                    Got it
                </button>
                {ctaLabel && ctaUrl ? (
                    <button
                        type="button"
                        onClick={onCta}
                        className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        {ctaLabel}
                    </button>
                ) : null}
            </div>
        </div>
    )

    return createPortal(
        <>
            {backdrop}
            {panel}
        </>,
        document.body
    )
}
