/**
 * Full-screen-ish review modal for Insights → Review tag & metadata candidate rows.
 * Large preview, explicit suggested value, Accept / Reject / Skip with motion transitions.
 */
import { useCallback, useEffect, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Link } from '@inertiajs/react'
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    CheckIcon,
    SparklesIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
} from '@heroicons/react/24/outline'

function itemKey(item) {
    if (item.type === 'value_suggestion') return `vs-${item.id}`
    if (item.type === 'field_suggestion') return `fs-${item.id}`
    return String(item.id)
}

function slideVariants(dir) {
    return {
        initial: { opacity: 0, x: dir >= 0 ? 48 : -48 },
        animate: { opacity: 1, x: 0 },
        exit: { opacity: 0, x: dir >= 0 ? -32 : 32 },
    }
}

export default function InsightAiSuggestionReviewModal({
    open,
    onClose,
    items = [],
    initialIndex = 0,
    processing = new Set(),
    canAccept = false,
    canReject = false,
    onApprove = async () => {},
    onReject = async () => {},
    accentHex = '#4f46e5',
}) {
    const [index, setIndex] = useState(0)
    const [slideDir, setSlideDir] = useState(1)

    useEffect(() => {
        if (!open) return
        const safe = items.length ? Math.min(Math.max(0, initialIndex), items.length - 1) : 0
        setIndex(safe)
    }, [open, initialIndex, items.length])

    useEffect(() => {
        if (!open || items.length === 0) return
        setIndex((i) => Math.min(i, items.length - 1))
    }, [open, items.length])

    useEffect(() => {
        if (open && items.length === 0) {
            onClose()
        }
    }, [open, items.length, onClose])

    const current = items[index] ?? null
    const pk = current ? itemKey(current) : ''
    const busy = pk ? processing.has(pk) : false
    const atStart = index <= 0
    const atEnd = index >= items.length - 1

    const go = useCallback(
        (delta) => {
            setSlideDir(delta >= 0 ? 1 : -1)
            setIndex((i) => {
                const n = i + delta
                if (n < 0) return 0
                if (n >= items.length) return Math.max(0, items.length - 1)
                return n
            })
        },
        [items.length]
    )

    const skipNext = useCallback(() => {
        if (atEnd) {
            onClose()
            return
        }
        go(1)
    }, [atEnd, go, onClose])

    useEffect(() => {
        if (!open) return
        const onKey = (e) => {
            if (e.key === 'Escape') return
            if (busy || !current) return
            if (e.target?.tagName === 'INPUT' || e.target?.tagName === 'TEXTAREA') return
            if (e.key === 'ArrowRight' || e.key === 'j' || e.key === 'J') {
                e.preventDefault()
                skipNext()
            }
            if (e.key === 'ArrowLeft' || e.key === 'k' || e.key === 'K') {
                e.preventDefault()
                if (!atStart) go(-1)
            }
            if ((e.key === 'a' || e.key === 'A') && canAccept) {
                e.preventDefault()
                void onApprove(current)
            }
            if ((e.key === 'r' || e.key === 'R') && canReject) {
                e.preventDefault()
                void onReject(current)
            }
        }
        window.addEventListener('keydown', onKey)
        return () => window.removeEventListener('keydown', onKey)
    }, [open, busy, current, atStart, skipNext, go, canAccept, canReject, onApprove, onReject])

    const headline = current
        ? current.type === 'tag'
            ? {
                  kicker: 'Suggested tag',
                  value: current.suggestion,
                  sub: 'Add this tag to the asset below if it fits your taxonomy.',
              }
            : {
                  kicker: `Suggested value · ${current.field_display_label || current.field_label || current.section_header || 'Metadata'}`,
                  value: current.suggestion,
                  sub: `Field: ${current.field_label || current.field_key || '—'}`,
              }
        : null

    return (
        <Dialog open={open} onClose={onClose} className="relative z-[80]">
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-[2px]" aria-hidden />
            <div className="fixed inset-0 flex items-center justify-center p-4 sm:p-6">
                <DialogPanel className="flex max-h-[min(92vh,900px)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200/80">
                    <div
                        className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"
                        style={{ borderBottomColor: `${accentHex}22` }}
                    >
                        <div className="flex items-center gap-2 min-w-0">
                            <SparklesIcon className="h-6 w-6 shrink-0 text-indigo-500" aria-hidden />
                            <div className="min-w-0">
                                <DialogTitle className="text-lg font-semibold text-slate-900 truncate">
                                    Quick review
                                </DialogTitle>
                                <p className="text-xs text-slate-500 truncate">
                                    {items.length > 0 ? (
                                        <>
                                            {index + 1} of {items.length} on this page · Accept, reject, or skip
                                        </>
                                    ) : (
                                        'No items'
                                    )}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            aria-label="Close"
                        >
                            <XMarkIcon className="h-6 w-6" />
                        </button>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        {!current ? (
                            <p className="py-12 text-center text-sm text-slate-500">Nothing to show.</p>
                        ) : (
                            <AnimatePresence mode="wait" initial={false}>
                                <motion.div
                                    key={pk}
                                    variants={slideVariants(slideDir)}
                                    initial="initial"
                                    animate="animate"
                                    exit="exit"
                                    transition={{ type: 'spring', stiffness: 380, damping: 32 }}
                                    className="space-y-5"
                                >
                                    {headline ? (
                                        <div className="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50/90 to-white px-4 py-4">
                                            <p className="text-[10px] font-semibold uppercase tracking-wide text-indigo-600">
                                                {headline.kicker}
                                            </p>
                                            <p className="mt-1 break-words text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                                                {headline.value}
                                            </p>
                                            <p className="mt-2 text-sm text-slate-600">{headline.sub}</p>
                                            {current.confidence != null && (
                                                <p className="mt-2 text-xs font-medium text-slate-500">
                                                    Model confidence ~{Math.round(current.confidence * 100)}%
                                                </p>
                                            )}
                                        </div>
                                    ) : null}

                                    <div>
                                        <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                            Asset preview
                                        </p>
                                        <button
                                            type="button"
                                            className="relative mx-auto flex max-h-[min(48vh,440px)] w-full max-w-lg items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                            aria-label="Enlarged thumbnail"
                                        >
                                            {current.thumbnail_url ? (
                                                <img
                                                    src={current.thumbnail_url}
                                                    alt=""
                                                    className="max-h-[min(48vh,440px)] w-full object-contain"
                                                />
                                            ) : (
                                                <div className="flex h-48 w-full items-center justify-center text-slate-400">
                                                    <SparklesIcon className="h-14 w-14" />
                                                </div>
                                            )}
                                        </button>
                                        <div className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-700">
                                            <span className="font-medium text-slate-900">
                                                {current.asset_title || current.asset_filename || 'Untitled asset'}
                                            </span>
                                            {current.asset_category ? (
                                                <span className="text-slate-500">· {current.asset_category}</span>
                                            ) : null}
                                            <Link
                                                href={`/app/assets?q=${encodeURIComponent(current.asset_id)}&asset=${encodeURIComponent(current.asset_id)}`}
                                                className="ml-auto inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                                onClick={() => onClose()}
                                            >
                                                Open in grid
                                                <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5" />
                                            </Link>
                                        </div>
                                    </div>
                                </motion.div>
                            </AnimatePresence>
                        )}
                    </div>

                    <div className="shrink-0 border-t border-slate-100 bg-slate-50/80 px-5 py-4">
                        <p className="mb-3 text-center text-[11px] text-slate-500">
                            Shortcuts: <kbd className="rounded bg-white px-1">A</kbd> accept ·{' '}
                            <kbd className="rounded bg-white px-1">R</kbd> reject ·{' '}
                            <kbd className="rounded bg-white px-1">→</kbd> or <kbd className="rounded bg-white px-1">J</kbd>{' '}
                            skip · <kbd className="rounded bg-white px-1">←</kbd> or <kbd className="rounded bg-white px-1">K</kbd>{' '}
                            back
                        </p>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex justify-center gap-2 sm:justify-start">
                                <button
                                    type="button"
                                    disabled={atStart}
                                    onClick={() => go(-1)}
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <ArrowLeftIcon className="h-4 w-4" />
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    onClick={() => skipNext()}
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                                >
                                    {atEnd ? 'Close' : 'Skip'}
                                    <ArrowRightIcon className="h-4 w-4" />
                                </button>
                            </div>
                            <div className="flex flex-1 flex-wrap justify-center gap-2 sm:justify-end">
                                {canReject && (
                                    <button
                                        type="button"
                                        disabled={busy || !current}
                                        onClick={() => current && void onReject(current)}
                                        className="inline-flex min-w-[7rem] flex-1 items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 shadow-sm hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-initial"
                                    >
                                        <XMarkIcon className="h-5 w-5" />
                                        Reject
                                    </button>
                                )}
                                {canAccept && (
                                    <button
                                        type="button"
                                        disabled={busy || !current}
                                        onClick={() => current && void onApprove(current)}
                                        className="inline-flex min-w-[7rem] flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50 sm:flex-initial"
                                        style={{ backgroundColor: accentHex }}
                                    >
                                        <CheckIcon className="h-5 w-5" />
                                        Accept
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}
