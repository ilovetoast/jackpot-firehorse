import { useState, useEffect, useCallback } from 'react'
import { SparklesIcon, CheckIcon, XMarkIcon, PauseIcon } from '@heroicons/react/24/outline'
import {
    RECOMMENDATION_INTENT,
    intentBadgeClasses,
    formatRecommendationScore,
    recommendationLabel,
    RECOMMENDATION_REVIEW_LABEL,
} from '../../utils/contextualNavigationRecommendations'

/**
 * Phase 6 — Contextual Navigation review tab body.
 *
 * Self-contained: owns its own fetch + state so the parent Review.jsx
 * doesn't need to grow a parallel item shape. The list endpoint is
 * `GET /app/api/ai/review?type=contextual`. Approve / reject / defer
 * route to the dedicated controller.
 */

/** Per-row gating: any in-flight action key starting with `${id}:` blocks
 *  every button on that row, while leaving other rows interactive. */
function isRowProcessing(processing, id) {
    const prefix = `${id}:`
    for (const key of processing) {
        if (typeof key === 'string' && key.startsWith(prefix)) return true
    }
    return false
}

function csrfHeaders() {
    const token =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
    }
}

export default function ContextualNavigationReviewTab({
    canManage = false,
    onCountsChanged,
    accentColor,
}) {
    const [items, setItems] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [page, setPage] = useState(1)
    const [pagination, setPagination] = useState({ total: 0, last_page: 1, per_page: 25, current_page: 1 })
    const [refresh, setRefresh] = useState(0)
    const [running, setRunning] = useState(false)
    const [runMessage, setRunMessage] = useState(null)

    const fetchPage = useCallback(async () => {
        setLoading(true)
        try {
            const url = `/app/api/ai/review?type=contextual&page=${page}&per_page=25`
            const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            if (!r.ok) {
                setItems([])
                setLoading(false)
                return
            }
            const data = await r.json()
            setItems(data.items || [])
            setPagination({
                total: data.total ?? 0,
                last_page: data.last_page ?? 1,
                per_page: data.per_page ?? 25,
                current_page: data.current_page ?? 1,
            })
        } catch {
            setItems([])
        } finally {
            setLoading(false)
        }
    }, [page])

    useEffect(() => {
        void fetchPage()
    }, [fetchPage, refresh])

    const handleAction = useCallback(
        async (item, action) => {
            const key = `${item.id}:${action}`
            setProcessing((prev) => new Set(prev).add(key))
            try {
                const r = await fetch(`/app/api/ai/review/contextual/${item.id}/${action}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: csrfHeaders(),
                    body: JSON.stringify({}),
                })
                if (r.ok) {
                    setItems((prev) => prev.filter((i) => i.id !== item.id))
                    onCountsChanged?.()
                    setRefresh((n) => n + 1)
                }
            } finally {
                setProcessing((prev) => {
                    const n = new Set(prev)
                    n.delete(key)
                    return n
                })
            }
        },
        [onCountsChanged],
    )

    const handleRun = useCallback(async (force = false) => {
        if (!canManage) return
        setRunning(true)
        setRunMessage(null)
        try {
            const r = await fetch('/app/api/ai/review/contextual/run', {
                method: 'POST',
                credentials: 'same-origin',
                headers: csrfHeaders(),
                body: JSON.stringify({ force }),
            })
            const data = await r.json().catch(() => ({}))
            if (r.ok) {
                setRunMessage('Analysis queued. Recommendations will appear shortly.')
                setTimeout(() => setRefresh((n) => n + 1), 1500)
            } else {
                setRunMessage(data?.message || 'Could not queue analysis.')
            }
        } catch {
            setRunMessage('Could not queue analysis.')
        } finally {
            setRunning(false)
        }
    }, [canManage])

    const empty = !loading && items.length === 0
    const accent = accentColor || '#0f172a'

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-4">
                <div>
                    <h3 className="text-sm font-semibold text-slate-900">Contextual navigation recommendations</h3>
                    <p className="mt-1 text-xs text-slate-600">
                        Statistical signals (and optional AI rationale) suggesting which filters to show, pin, or move out of folder navigation.
                        Approving applies the change through your existing folder filter settings.
                    </p>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => handleRun(false)}
                            disabled={running}
                            className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        >
                            <SparklesIcon className="h-4 w-4" />
                            {running ? 'Queuing…' : 'Analyze contextual navigation'}
                        </button>
                    </div>
                )}
            </div>

            {runMessage && (
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    {runMessage}
                </div>
            )}

            {loading ? (
                <div className="rounded-lg bg-white p-8 text-center text-sm text-slate-500">Loading recommendations…</div>
            ) : empty ? (
                <div className="rounded-lg bg-white p-8 text-center">
                    <SparklesIcon className="mx-auto h-12 w-12 text-slate-300" />
                    <p className="mt-2 text-sm text-slate-600">No pending contextual navigation recommendations.</p>
                    {canManage && (
                        <p className="mt-1 text-xs text-slate-500">
                            Use “Analyze contextual navigation” to scan now (or wait for the weekly run).
                        </p>
                    )}
                </div>
            ) : (
                <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    {items.map((item) => {
                        const intent = RECOMMENDATION_INTENT[item.recommendation_type] || 'neutral'
                        const typeLabel = recommendationLabel(
                            item.recommendation_type,
                            RECOMMENDATION_REVIEW_LABEL,
                        )
                        const scoreLabel = formatRecommendationScore(item.score)
                        return (
                            <li key={item.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${intentBadgeClasses(intent)}`}
                                        >
                                            {typeLabel}
                                        </span>
                                        {scoreLabel && (
                                            <span className="text-xs text-slate-500">score {scoreLabel}</span>
                                        )}
                                        {item.source && item.source !== 'statistical' && (
                                            <span className="text-xs text-slate-400">· {item.source}</span>
                                        )}
                                    </div>
                                    <div className="mt-1 text-sm text-slate-900">
                                        <span className="font-medium">{item.field?.label || item.field?.key || 'Field'}</span>
                                        {item.folder?.name && (
                                            <span className="text-slate-500"> · in {item.folder.name}</span>
                                        )}
                                    </div>
                                    {item.reason_summary && (
                                        <p className="mt-1 text-sm text-slate-600">{item.reason_summary}</p>
                                    )}
                                </div>
                                <div className="flex flex-shrink-0 items-center gap-2">
                                    {item.is_actionable && canManage && (
                                        <button
                                            type="button"
                                            onClick={() => handleAction(item, 'approve')}
                                            disabled={isRowProcessing(processing, item.id)}
                                            className="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
                                            style={{ backgroundColor: accent }}
                                        >
                                            <CheckIcon className="h-4 w-4" />
                                            Approve
                                        </button>
                                    )}
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => handleAction(item, 'defer')}
                                            disabled={isRowProcessing(processing, item.id)}
                                            className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                        >
                                            <PauseIcon className="h-4 w-4" />
                                            Defer
                                        </button>
                                    )}
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => handleAction(item, 'reject')}
                                            disabled={isRowProcessing(processing, item.id)}
                                            className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                        >
                                            <XMarkIcon className="h-4 w-4" />
                                            Reject
                                        </button>
                                    )}
                                </div>
                            </li>
                        )
                    })}
                </ul>
            )}

            {pagination.last_page > 1 && (
                <div className="flex items-center justify-between text-xs text-slate-600">
                    <span>
                        Page {pagination.current_page} of {pagination.last_page} · {pagination.total} total
                    </span>
                    <div className="flex gap-1">
                        <button
                            type="button"
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={page <= 1}
                            className="rounded-md border border-slate-300 bg-white px-2 py-1 disabled:opacity-50"
                        >
                            Prev
                        </button>
                        <button
                            type="button"
                            onClick={() => setPage((p) => Math.min(pagination.last_page, p + 1))}
                            disabled={page >= pagination.last_page}
                            className="rounded-md border border-slate-300 bg-white px-2 py-1 disabled:opacity-50"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    )
}
