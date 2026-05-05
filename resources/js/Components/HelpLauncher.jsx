import { useCallback, useEffect, useRef, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { router, usePage } from '@inertiajs/react'
import {
    ArrowLeftIcon,
    ArrowPathIcon,
    QuestionMarkCircleIcon,
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
                            <div className="shrink-0 border-b border-gray-100 bg-white px-4 py-3">
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
                        )}

                        <div className="min-h-0 flex-1 overflow-y-auto bg-white px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            {selected ? (
                                <HelpActionDetail
                                    action={selected}
                                    onGo={() => goToPage(selected)}
                                    onPickRelated={(a) => setSelected(a)}
                                    resolveVisitHref={resolveVisitHref}
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

function HelpActionDetail({ action, onGo, onPickRelated, resolveVisitHref }) {
    const href = resolveVisitHref(action)
    const canGo = Boolean(href)

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
            <div>
                <button
                    type="button"
                    disabled={!canGo}
                    onClick={onGo}
                    className="inline-flex min-h-[44px] w-full items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-95 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                >
                    Go to page
                </button>
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
