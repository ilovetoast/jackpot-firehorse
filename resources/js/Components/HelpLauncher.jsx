import { useCallback, useEffect, useRef, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import {
    ArrowLeftIcon,
    QuestionMarkCircleIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

/**
 * App help: slide-over panel with search (debounced) against /app/help/actions.
 */
export default function HelpLauncher({ textColor = '#000000' }) {
    const { auth } = usePage().props
    const activeBrand = auth?.activeBrand
    const [open, setOpen] = useState(false)
    const [query, setQuery] = useState('')
    const [debouncedQuery, setDebouncedQuery] = useState('')
    const [loading, setLoading] = useState(false)
    const [payload, setPayload] = useState({ query: null, results: [], common: [] })
    const [selected, setSelected] = useState(null)
    const searchRef = useRef(null)

    useEffect(() => {
        const t = setTimeout(() => setDebouncedQuery(query.trim()), 300)
        return () => clearTimeout(t)
    }, [query])

    useEffect(() => {
        if (!open) {
            return
        }
        let cancelled = false
        const url = debouncedQuery
            ? `/app/help/actions?q=${encodeURIComponent(debouncedQuery)}`
            : '/app/help/actions'
        setLoading(true)
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
    }, [open, debouncedQuery])

    useEffect(() => {
        setSelected(null)
    }, [debouncedQuery])

    useEffect(() => {
        if (!open) {
            return undefined
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                setOpen(false)
            }
        }
        document.addEventListener('keydown', onKey)
        const id = requestAnimationFrame(() => searchRef.current?.focus())
        return () => {
            document.removeEventListener('keydown', onKey)
            cancelAnimationFrame(id)
        }
    }, [open])

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
            router.visit(href)
        },
        [resolveVisitHref]
    )

    const listItems = debouncedQuery ? payload.results : payload.common
    const showSecondaryCommon =
        Boolean(debouncedQuery) && payload.results.length === 0 && payload.common.length > 0

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-full p-1.5 transition-colors hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                style={{ color: textColor }}
                aria-expanded={open}
                aria-haspopup="dialog"
                title="Help"
            >
                <span className="sr-only">Open help</span>
                <QuestionMarkCircleIcon className="h-6 w-6" aria-hidden />
            </button>

            {open && (
                <div className="fixed inset-0 z-[220] flex justify-end" role="dialog" aria-modal="true" aria-labelledby="jp-help-title">
                    <button
                        type="button"
                        className="absolute inset-0 bg-gray-900/40"
                        aria-label="Close help"
                        onClick={() => setOpen(false)}
                    />
                    <div className="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl border-l border-gray-200">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-3">
                            {selected ? (
                                <button
                                    type="button"
                                    onClick={() => setSelected(null)}
                                    className="inline-flex items-center gap-1 text-sm font-medium text-gray-700 hover:text-gray-900"
                                >
                                    <ArrowLeftIcon className="h-4 w-4" />
                                    Back
                                </button>
                            ) : (
                                <h2 id="jp-help-title" className="text-sm font-semibold text-gray-900">
                                    Help
                                </h2>
                            )}
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>

                        {!selected && (
                            <div className="shrink-0 border-b border-gray-100 px-4 py-3">
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
                                    className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        )}

                        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                            {selected ? (
                                <HelpActionDetail
                                    action={selected}
                                    onGo={() => goToPage(selected)}
                                    onPickRelated={(a) => setSelected(a)}
                                    resolveVisitHref={resolveVisitHref}
                                />
                            ) : loading ? (
                                <p className="text-sm text-gray-500">Loading…</p>
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
                                                        className="flex w-full flex-col rounded-md px-2 py-2 text-left text-sm hover:bg-gray-50"
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
                                                            className="flex w-full flex-col rounded-md px-2 py-2 text-left text-sm hover:bg-gray-50"
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
                    </div>
                </div>
            )}
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
                <h3 className="mt-1 text-base font-semibold text-gray-900">{action.title}</h3>
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
                    className="inline-flex w-full items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-95 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500"
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
                                className="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
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
