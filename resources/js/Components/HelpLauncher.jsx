import { useCallback, useEffect, useRef, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { router, usePage } from '@inertiajs/react'
import {
    ArrowLeftIcon,
    ArrowPathIcon,
    QuestionMarkCircleIcon,
    SparklesIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

/**
 * App help: slide-over panel with search (debounced) against /app/help/actions.
 * Uses Headless UI Dialog for focus trap, Escape/outside dismiss, and scroll locking.
 */
export default function HelpLauncher({ textColor = '#000000' }) {
    const { auth } = usePage().props
    const activeBrand = auth?.activeBrand
    const [open, setOpen] = useState(false)
    const [query, setQuery] = useState('')
    const [debouncedQuery, setDebouncedQuery] = useState('')
    const [loading, setLoading] = useState(false)
    const [loadError, setLoadError] = useState(false)
    const [retryToken, setRetryToken] = useState(0)
    const [payload, setPayload] = useState({ query: null, results: [], common: [] })
    const [selected, setSelected] = useState(null)
    const [askQuestion, setAskQuestion] = useState('')
    const [askLoading, setAskLoading] = useState(false)
    const [askError, setAskError] = useState(false)
    const [askResult, setAskResult] = useState(null)
    const searchRef = useRef(null)
    const closeBtnRef = useRef(null)

    useEffect(() => {
        const t = setTimeout(() => setDebouncedQuery(query.trim()), 300)
        return () => clearTimeout(t)
    }, [query])

    useEffect(() => {
        if (!open) {
            return
        }
        let cancelled = false
        setLoading(true)
        setLoadError(false)
        const url = debouncedQuery
            ? `/app/help/actions?q=${encodeURIComponent(debouncedQuery)}`
            : '/app/help/actions'
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((data) => {
                if (!cancelled) {
                    setPayload({
                        query: data.query ?? null,
                        results: Array.isArray(data.results) ? data.results : [],
                        common: Array.isArray(data.common) ? data.common : [],
                    })
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoadError(true)
                    setPayload({ query: debouncedQuery || null, results: [], common: [] })
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false)
                }
            })
        return () => {
            cancelled = true
        }
    }, [open, debouncedQuery, retryToken])

    useEffect(() => {
        setSelected(null)
    }, [debouncedQuery])

    useEffect(() => {
        if (!open) {
            setAskQuestion('')
            setAskLoading(false)
            setAskError(false)
            setAskResult(null)
        }
    }, [open])

    const submitAsk = useCallback(async () => {
        const q = askQuestion.trim()
        if (!q || askLoading) {
            return
        }
        setAskLoading(true)
        setAskError(false)
        setAskResult(null)
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        try {
            const r = await fetch('/app/help/ask', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ question: q }),
            })
            if (!r.ok) {
                throw new Error(String(r.status))
            }
            const data = await r.json()
            setAskResult(data && typeof data === 'object' ? data : null)
        } catch {
            setAskError(true)
        } finally {
            setAskLoading(false)
        }
    }, [askQuestion, askLoading])

    useEffect(() => {
        if (!open) {
            return undefined
        }
        const id = requestAnimationFrame(() => {
            if (selected) {
                closeBtnRef.current?.focus()
            } else {
                searchRef.current?.focus()
            }
        })
        return () => cancelAnimationFrame(id)
    }, [open, selected])

    const handleDialogClose = useCallback(() => {
        if (selected) {
            setSelected(null)
            return
        }
        setOpen(false)
    }, [selected])

    const resolveVisitHref = useCallback(
        (action) => {
            if (!action) {
                return null
            }
            if (action.url) {
                return action.url
            }
            const name = action.route_name
            if (!name || typeof route === 'undefined' || typeof route !== 'function') {
                return null
            }
            try {
                const needsBrand = ['brands.edit', 'brands.approvals'].includes(name)
                if (needsBrand) {
                    if (!activeBrand?.id) {
                        return null
                    }
                    return route(name, { brand: activeBrand.id })
                }
                return route(name)
            } catch {
                return null
            }
        },
        [activeBrand?.id]
    )

    const goToPage = useCallback(
        (action) => {
            const href = resolveVisitHref(action)
            if (!href) {
                return
            }
            setOpen(false)
            setSelected(null)
            router.visit(href)
        },
        [resolveVisitHref]
    )

    const buildShowMeHref = useCallback((action) => {
        if (!action?.highlight?.selector || typeof action.highlight.selector !== 'string') {
            return null
        }
        const base = resolveVisitHref(action)
        if (!base) {
            return null
        }
        try {
            const u = new URL(base, typeof window !== 'undefined' ? window.location.origin : 'http://localhost')
            u.searchParams.set('help', String(action.key || ''))
            u.searchParams.set('highlight', action.highlight.selector)
            return u.pathname + u.search + u.hash
        } catch {
            return null
        }
    }, [resolveVisitHref])

    const showMe = useCallback(
        (action) => {
            const href = buildShowMeHref(action)
            if (!href) {
                return
            }
            setOpen(false)
            setSelected(null)
            router.visit(href)
        },
        [buildShowMeHref]
    )

    const listItems = debouncedQuery ? payload.results : payload.common
    const showSecondaryCommon =
        Boolean(debouncedQuery) && payload.results.length === 0 && payload.common.length > 0 && !loadError

    return (
        <>
            <button
                type="button"
                onClick={() => {
                    setOpen(true)
                    setLoadError(false)
                }}
                className="rounded-full p-1.5 transition-colors hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                style={{ color: textColor }}
                aria-expanded={open}
                aria-haspopup="dialog"
                title="Help"
            >
                <span className="sr-only">Open help</span>
                <QuestionMarkCircleIcon className="h-6 w-6" aria-hidden />
            </button>

            <Dialog open={open} onClose={handleDialogClose} className="relative z-[220]">
                <div className="fixed inset-0 bg-gray-900/40" aria-hidden />
                <div className="fixed inset-0 flex justify-end">
                    <DialogPanel className="flex h-[100dvh] max-h-[100dvh] w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-xl outline-none">
                        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-3 pt-[max(0.75rem,env(safe-area-inset-top))]">
                            {selected ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setSelected(null)}
                                        className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-gray-700 hover:text-gray-900"
                                    >
                                        <ArrowLeftIcon className="h-4 w-4" aria-hidden />
                                        Back
                                    </button>
                                    <DialogTitle className="min-w-0 flex-1 truncate text-left text-sm font-semibold text-gray-900">
                                        {selected.title}
                                    </DialogTitle>
                                </>
                            ) : (
                                <DialogTitle id="jp-help-title" className="text-sm font-semibold text-gray-900">
                                    Help
                                </DialogTitle>
                            )}
                            <button
                                ref={closeBtnRef}
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                                aria-label="Close help"
                            >
                                <XMarkIcon className="h-5 w-5" aria-hidden />
                            </button>
                        </div>

                        {!selected && (
                            <div className="shrink-0 space-y-3 border-b border-gray-100 bg-white px-4 py-3">
                                <div>
                                    <label htmlFor="jp-help-search" className="sr-only">
                                        Search help
                                    </label>
                                    <input
                                        ref={searchRef}
                                        id="jp-help-search"
                                        type="search"
                                        autoComplete="off"
                                        placeholder="Search topics…"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                                <div className="rounded-lg border border-gray-200 bg-gray-50/80 p-3">
                                    <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        <SparklesIcon className="h-4 w-4 text-violet-600" aria-hidden />
                                        Ask AI
                                    </div>
                                    <p className="mb-2 text-xs text-gray-500">
                                        Answers use only your workspace&apos;s documented help topics — not general chat.
                                    </p>
                                    <label htmlFor="jp-help-ask" className="sr-only">
                                        Ask a question
                                    </label>
                                    <textarea
                                        id="jp-help-ask"
                                        rows={3}
                                        value={askQuestion}
                                        onChange={(e) => setAskQuestion(e.target.value)}
                                        placeholder="e.g. How do I invite someone to my company?"
                                        className="block w-full resize-y rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <button
                                        type="button"
                                        disabled={askLoading || askQuestion.trim() === ''}
                                        onClick={() => submitAsk()}
                                        className="mt-2 inline-flex min-h-[40px] w-full items-center justify-center gap-1.5 rounded-md bg-violet-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-violet-500 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2"
                                    >
                                        {askLoading ? (
                                            <>
                                                <ArrowPathIcon className="h-4 w-4 animate-spin" aria-hidden />
                                                Thinking…
                                            </>
                                        ) : (
                                            <>
                                                <SparklesIcon className="h-4 w-4" aria-hidden />
                                                Get answer
                                            </>
                                        )}
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="min-h-0 flex-1 overflow-y-auto bg-white px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            {selected ? (
                                <HelpActionDetail
                                    action={selected}
                                    onGo={() => goToPage(selected)}
                                    onShowMe={() => showMe(selected)}
                                    onPickRelated={(a) => setSelected(a)}
                                    resolveVisitHref={resolveVisitHref}
                                    showMeHref={buildShowMeHref(selected)}
                                />
                            ) : loadError ? (
                                <div className="rounded-lg border border-red-100 bg-red-50/90 px-3 py-5 text-center">
                                    <p className="text-sm font-medium text-red-800">Could not load help topics</p>
                                    <p className="mt-1 text-xs text-red-700/90">Check your connection and try again.</p>
                                    <button
                                        type="button"
                                        onClick={() => setRetryToken((t) => t + 1)}
                                        className="mt-4 inline-flex items-center justify-center gap-1.5 rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-800 shadow-sm hover:bg-red-50"
                                    >
                                        <ArrowPathIcon className="h-4 w-4" aria-hidden />
                                        Retry
                                    </button>
                                </div>
                            ) : loading ? (
                                <div className="space-y-2 py-2" aria-busy="true" aria-live="polite">
                                    <p className="text-sm text-gray-500">Loading topics…</p>
                                    <div className="h-2 w-[66%] animate-pulse rounded bg-gray-200" />
                                    <div className="h-2 w-full animate-pulse rounded bg-gray-100" />
                                    <div className="h-2 w-[83%] animate-pulse rounded bg-gray-100" />
                                </div>
                            ) : listItems.length === 0 && !showSecondaryCommon ? (
                                <div className="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-3 py-6 text-center">
                                    <p className="text-sm text-gray-600">
                                        {debouncedQuery
                                            ? 'No topics match that search. Try different keywords or browse common topics below.'
                                            : 'No help topics are available for your current access.'}
                                    </p>
                                </div>
                            ) : (
                                <>
                                    {askError && (
                                        <div className="mb-4 rounded-lg border border-red-100 bg-red-50/90 px-3 py-2 text-sm text-red-800">
                                            Could not reach AI help. Try again in a moment.
                                        </div>
                                    )}
                                    {askResult && (
                                        <div className="mb-4">
                                            <HelpAskResultBlock
                                                result={askResult}
                                                onPickTopic={(item) => {
                                                    setSelected(item)
                                                    setAskResult(null)
                                                }}
                                                resolveVisitHref={resolveVisitHref}
                                            />
                                        </div>
                                    )}
                                    {listItems.length > 0 && (
                                        <ul className="space-y-1">
                                            {listItems.map((item) => (
                                                <li key={item.key}>
                                                    <button
                                                        type="button"
                                                        onClick={() => setSelected(item)}
                                                        className="flex w-full min-h-[44px] flex-col justify-center rounded-md px-2 py-2 text-left text-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-inset"
                                                    >
                                                        <span className="font-medium text-gray-900">{item.title}</span>
                                                        {item.category ? (
                                                            <span className="text-xs text-gray-500">{item.category}</span>
                                                        ) : null}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {showSecondaryCommon && (
                                        <div className="mt-6">
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                Common topics
                                            </p>
                                            <ul className="space-y-1">
                                                {payload.common.map((item) => (
                                                    <li key={item.key}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelected(item)}
                                                            className="flex w-full min-h-[44px] flex-col justify-center rounded-md px-2 py-2 text-left text-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-inset"
                                                        >
                                                            <span className="font-medium text-gray-900">{item.title}</span>
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </DialogPanel>
                </div>
            </Dialog>
        </>
    )
}

function HelpAskResultBlock({ result, onPickTopic, resolveVisitHref }) {
    const kind = result?.kind
    if (kind === 'ai' && result.answer) {
        const a = result.answer
        const rec = a.recommended_page
        const recHref = rec ? resolveVisitHref(rec) : null
        const conf = a.confidence || 'low'
        const confClass =
            conf === 'high' ? 'bg-emerald-100 text-emerald-900' : conf === 'medium' ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-700'
        return (
            <div className="rounded-lg border border-violet-200 bg-violet-50/40 p-3 text-sm">
                <div className="mb-2 flex flex-wrap items-center gap-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${confClass}`}>Confidence: {conf}</span>
                </div>
                <p className="text-gray-900">{a.direct_answer}</p>
                {Array.isArray(a.numbered_steps) && a.numbered_steps.length > 0 && (
                    <ol className="mt-3 list-decimal space-y-1 pl-4 text-gray-800">
                        {a.numbered_steps.map((s, i) => (
                            <li key={i}>{s}</li>
                        ))}
                    </ol>
                )}
                {rec && (
                    <div className="mt-3">
                        {recHref ? (
                            <button
                                type="button"
                                onClick={() => router.visit(recHref)}
                                className="inline-flex text-left text-sm font-medium text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                            >
                                {rec.title || rec.key} →
                            </button>
                        ) : (
                            <span className="text-sm text-gray-600">{rec.title || rec.key}</span>
                        )}
                    </div>
                )}
                {Array.isArray(a.related_actions) && a.related_actions.length > 0 && (
                    <div className="mt-3">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Related</p>
                        <div className="flex flex-wrap gap-2">
                            {a.related_actions.map((rel) => (
                                <span key={rel.key} className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-700">
                                    {rel.title}
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        )
    }
    if (kind === 'fallback_action' && result.primary) {
        return (
            <div className="rounded-lg border border-amber-200 bg-amber-50/50 p-3 text-sm text-gray-800">
                <p className="mb-2">{result.message}</p>
                <button
                    type="button"
                    onClick={() => onPickTopic(result.primary)}
                    className="text-left font-medium text-violet-700 underline decoration-violet-200 underline-offset-2 hover:text-violet-900"
                >
                    Open: {result.primary.title}
                </button>
            </div>
        )
    }
    if (kind === 'fallback' || kind === 'ai_disabled') {
        const suggested = Array.isArray(result.suggested) ? result.suggested : []
        return (
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-800">
                <p className="mb-2">{result.message}</p>
                {suggested.length > 0 && (
                    <>
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Suggested topics</p>
                        <ul className="space-y-1">
                            {suggested.map((item) => (
                                <li key={item.key}>
                                    <button
                                        type="button"
                                        onClick={() => onPickTopic(item)}
                                        className="text-left text-sm font-medium text-violet-700 hover:text-violet-900"
                                    >
                                        {item.title}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        )
    }
    return null
}

function HelpActionDetail({ action, onGo, onShowMe, onPickRelated, resolveVisitHref, showMeHref }) {
    const href = resolveVisitHref(action)
    const canGo = Boolean(href)
    const canShowMe = Boolean(showMeHref)

    return (
        <div className="space-y-4">
            <div>
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{action.page_label || action.category}</p>
                <p className="mt-1 text-base font-semibold text-gray-900">{action.title}</p>
                <p className="mt-2 text-sm text-gray-600">{action.short_answer}</p>
            </div>
            {Array.isArray(action.steps) && action.steps.length > 0 && (
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Steps</p>
                    <ol className="mt-2 list-decimal space-y-1.5 pl-4 text-sm text-gray-700">
                        {action.steps.map((step, i) => (
                            <li key={i}>{step}</li>
                        ))}
                    </ol>
                </div>
            )}
            <div className="flex flex-col gap-2">
                <button
                    type="button"
                    disabled={!canGo}
                    onClick={onGo}
                    className="inline-flex min-h-[44px] w-full items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-95 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                >
                    Go to page
                </button>
                {canShowMe && (
                    <button
                        type="button"
                        onClick={onShowMe}
                        className="inline-flex min-h-[44px] w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                    >
                        Show me
                    </button>
                )}
                {!canGo && action.route_name && (
                    <p className="mt-2 text-xs text-gray-500">
                        Open this topic from a workspace with the right context (for example, an active brand).
                    </p>
                )}
            </div>
            {Array.isArray(action.related) && action.related.length > 0 && (
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Related</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {action.related.map((rel) => (
                            <button
                                key={rel.key}
                                type="button"
                                onClick={() => onPickRelated(rel)}
                                className="min-h-[36px] rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1"
                            >
                                {rel.title}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}
