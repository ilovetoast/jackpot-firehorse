import { useEffect } from 'react'
import { ShieldExclamationIcon } from '@heroicons/react/24/outline'

/**
 * In-app permission denied dialog (replaces Inertia's full-screen error for 403 when logged in).
 * theme: brand (CSS --primary), workspace (app chrome), jackpot (default indigo).
 */
export default function PermissionDeniedModal({ open, onClose, title, message, theme = 'jackpot' }) {
    useEffect(() => {
        if (!open) return
        const onKey = (e) => {
            if (e.key === 'Escape') onClose()
        }
        document.addEventListener('keydown', onKey)
        return () => document.removeEventListener('keydown', onKey)
    }, [open, onClose])

    useEffect(() => {
        if (!open) return
        const prev = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        return () => {
            document.body.style.overflow = prev
        }
    }, [open])

    if (!open) return null

    const primary =
        theme === 'brand'
            ? 'var(--primary, #6366f1)'
            : theme === 'workspace'
              ? '#4f46e5'
              : '#6366f1'

    const ring =
        theme === 'brand'
            ? 'ring-2 ring-[color:var(--primary,#6366f1)]/25'
            : theme === 'workspace'
              ? 'ring-2 ring-indigo-500/20'
              : 'ring-2 ring-indigo-500/20'

    const iconBg =
        theme === 'brand' ? 'bg-[color:var(--primary,#6366f1)]/10' : theme === 'workspace' ? 'bg-indigo-50' : 'bg-indigo-50'

    const iconColor =
        theme === 'brand' ? 'text-[color:var(--primary,#6366f1)]' : theme === 'workspace' ? 'text-indigo-600' : 'text-indigo-600'

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="permission-denied-title">
            <button
                type="button"
                className="absolute inset-0 bg-black/60 backdrop-blur-sm"
                onClick={onClose}
                aria-label="Close"
            />
            <div
                className={`relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ${ring}`}
                style={{ borderLeftWidth: theme === 'brand' ? 4 : undefined, borderLeftColor: theme === 'brand' ? primary : undefined }}
            >
                <div className="px-6 py-6 sm:px-8 sm:py-8">
                    <div className="flex items-start gap-4">
                        <div className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl ${iconBg}`}>
                            <ShieldExclamationIcon className={`h-7 w-7 ${iconColor}`} aria-hidden />
                        </div>
                        <div className="min-w-0 flex-1 text-left">
                            <h2 id="permission-denied-title" className="text-lg font-semibold text-gray-900">
                                {title}
                            </h2>
                            <p className="mt-2 text-sm leading-relaxed text-gray-600">{message}</p>
                        </div>
                    </div>
                    <div className="mt-8 flex flex-wrap items-center justify-end gap-3">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-w-[100px] justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style={{
                                backgroundColor: primary,
                                outlineColor: primary,
                            }}
                        >
                            OK
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
