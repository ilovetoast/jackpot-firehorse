import { useEffect, useCallback } from 'react'
import {
    ExclamationTriangleIcon,
    SignalSlashIcon,
    ServerStackIcon,
} from '@heroicons/react/24/outline'
import { useErrorStore } from '../stores/errorStore'

const typeStyles = {
    server: {
        icon: ServerStackIcon,
        accent: 'border-l-rose-500',
        iconWrap: 'bg-rose-50 text-rose-600',
        label: 'Server error',
    },
    validation: {
        icon: ExclamationTriangleIcon,
        accent: 'border-l-amber-500',
        iconWrap: 'bg-amber-50 text-amber-700',
        label: 'Validation',
    },
    network: {
        icon: SignalSlashIcon,
        accent: 'border-l-sky-500',
        iconWrap: 'bg-sky-50 text-sky-700',
        label: 'Connection',
    },
}

export default function GlobalErrorDialog() {
    const error = useErrorStore((s) => s.error)
    const clearError = useErrorStore((s) => s.clearError)

    const onClose = useCallback(() => clearError(), [clearError])

    useEffect(() => {
        if (!error) return
        const onKey = (e) => {
            if (e.key === 'Escape') onClose()
        }
        document.addEventListener('keydown', onKey)
        return () => document.removeEventListener('keydown', onKey)
    }, [error, onClose])

    useEffect(() => {
        if (!error?.autoDismissMs) return undefined
        const t = window.setTimeout(() => clearError(), error.autoDismissMs)
        return () => window.clearTimeout(t)
    }, [error, clearError])

    useEffect(() => {
        if (!error) return
        const prev = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        return () => {
            document.body.style.overflow = prev
        }
    }, [error])

    if (!error) return null

    const variant = typeStyles[error.type] || typeStyles.server
    const Icon = variant.icon

    return (
        <div
            className="fixed inset-0 z-[205] flex items-center justify-center p-4 sm:p-6"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="global-error-title"
        >
            <button
                type="button"
                className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                onClick={onClose}
                aria-label="Dismiss"
            />
            <div
                className={`relative w-full max-w-md overflow-hidden rounded-2xl border-l-4 bg-white shadow-2xl ring-1 ring-black/5 ${variant.accent}`}
            >
                <div className="px-6 py-6 sm:px-8 sm:py-8">
                    <div className="flex items-start gap-4">
                        <div
                            className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl ${variant.iconWrap}`}
                        >
                            <Icon className="h-7 w-7" aria-hidden />
                        </div>
                        <div className="min-w-0 flex-1 text-left">
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {variant.label}
                                {error.statusCode != null ? (
                                    <span className="ml-2 font-mono text-[11px] text-gray-400">
                                        {error.statusCode}
                                    </span>
                                ) : null}
                            </p>
                            <h2
                                id="global-error-title"
                                className="mt-1 text-lg font-semibold text-gray-900"
                            >
                                Something went wrong
                            </h2>
                            <p className="mt-2 text-sm leading-relaxed text-gray-600">
                                {error.message}
                            </p>
                        </div>
                    </div>
                    <div className="mt-8 flex flex-wrap items-center justify-end gap-3">
                        {typeof error.retry === 'function' ? (
                            <button
                                type="button"
                                onClick={() => {
                                    error.retry()
                                    onClose()
                                }}
                                className="inline-flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-400"
                            >
                                Retry
                            </button>
                        ) : null}
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-w-[100px] justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
