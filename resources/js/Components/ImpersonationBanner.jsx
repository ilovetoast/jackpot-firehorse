import { router } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

function formatRemaining(seconds) {
    if (seconds == null || Number.isNaN(seconds)) {
        return null
    }
    const s = Math.max(0, Math.floor(seconds))
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    const r = s % 60
    if (h > 0) {
        return `${h}h ${m}m`
    }
    if (m > 0) {
        return `${m}m ${r}s`
    }
    return `${r}s`
}

export default function ImpersonationBanner({ impersonation }) {
    const [exiting, setExiting] = useState(false)
    const [tick, setTick] = useState(0)

    useEffect(() => {
        if (!impersonation?.active || impersonation?.remaining_seconds == null) {
            return
        }
        const id = window.setInterval(() => setTick((n) => n + 1), 1000)
        return () => window.clearInterval(id)
    }, [impersonation?.active, impersonation?.remaining_seconds])

    if (!impersonation?.active || !impersonation.target) {
        return null
    }

    const isFull = impersonation.mode === 'full'
    const name =
        [impersonation.target.first_name, impersonation.target.last_name].filter(Boolean).join(' ').trim() ||
        impersonation.target.email
    const company = impersonation.tenant?.name || 'Company'
    const baseRemaining =
        impersonation.remaining_seconds != null ? Math.max(0, impersonation.remaining_seconds - tick) : null
    const remainingLabel = formatRemaining(baseRemaining)

    const exit = () => {
        if (exiting) return
        setExiting(true)
        router.post(
            impersonation.exit_url || '/app/impersonation/stop',
            {},
            {
                preserveScroll: true,
                onFinish: () => setExiting(false),
            }
        )
    }

    const shell = isFull
        ? 'border-b border-red-700/90 bg-red-950 px-4 py-2.5 text-center text-sm text-red-50 sm:px-6'
        : 'border-b border-indigo-400/35 bg-gradient-to-r from-indigo-950 via-violet-950 to-indigo-950 px-4 py-2.5 text-center text-sm text-indigo-50 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] sm:px-6'

    return (
        <div className={shell} role="status" aria-live="polite">
            <div className="mx-auto flex max-w-7xl flex-col items-center justify-center gap-2 sm:flex-row sm:flex-wrap sm:justify-between">
                <p className={`font-medium ${isFull ? 'text-red-50' : 'text-indigo-50'}`}>
                    <span className={isFull ? 'font-semibold' : 'font-semibold tracking-tight text-white'}>Support session</span>
                    {' — '}
                    viewing as <span className={isFull ? 'text-white' : 'font-semibold text-violet-100'}>{name}</span>
                    {' · '}
                    <span className={`font-normal ${isFull ? 'opacity-95' : 'text-indigo-100/90'}`}>{company}</span>
                    {' · '}
                    <span className={`font-normal ${isFull ? 'opacity-95' : 'text-indigo-100/90'}`}>{impersonation.mode_label || impersonation.mode}</span>
                    {remainingLabel ? (
                        <>
                            {' · '}
                            <span className={`font-normal tabular-nums ${isFull ? 'opacity-95' : 'text-violet-200'}`}>expires in {remainingLabel}</span>
                        </>
                    ) : null}
                </p>
                <button
                    type="button"
                    onClick={exit}
                    disabled={exiting}
                    className={
                        isFull
                            ? 'inline-flex items-center gap-1 rounded-md border border-red-400/90 bg-red-900/80 px-3 py-1 text-xs font-semibold text-white shadow-sm hover:bg-red-800 disabled:opacity-50'
                            : 'inline-flex items-center gap-1 rounded-md border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold text-white shadow-sm backdrop-blur-sm hover:bg-white/20 disabled:opacity-50'
                    }
                >
                    <XMarkIcon className="h-4 w-4" aria-hidden />
                    Exit session
                </button>
            </div>
        </div>
    )
}
