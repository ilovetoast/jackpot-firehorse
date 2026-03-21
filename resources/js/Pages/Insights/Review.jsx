import { useState, useEffect, useMemo } from 'react'
import { Link } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import {
    SparklesIcon,
    TagIcon,
    FolderIcon,
    CheckIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
    ListBulletIcon,
    RectangleStackIcon,
} from '@heroicons/react/24/outline'
import { usePermission } from '../../hooks/usePermission'

const VALID_TABS = ['tags', 'categories', 'values', 'fields']

export default function AnalyticsReview({ initialTab = 'tags', canCreateFieldFromSuggestion = false }) {
    const [activeTab, setActiveTab] = useState(() =>
        VALID_TABS.includes(initialTab) ? initialTab : 'tags'
    )
    const [items, setItems] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const { can } = usePermission()
    const canView = can('metadata.suggestions.view') || can('metadata.review_candidates')
    const canAccept = can('metadata.suggestions.apply') || can('metadata.edit_post_upload')
    const canReject = can('metadata.suggestions.dismiss') || can('metadata.edit_post_upload')
    const canCreateField =
        canCreateFieldFromSuggestion ||
        can('metadata.tenant.field.create') ||
        can('metadata.tenant.field.manage')

    useEffect(() => {
        if (VALID_TABS.includes(initialTab)) {
            setActiveTab(initialTab)
        }
    }, [initialTab])

    useEffect(() => {
        if (!canView) {
            setLoading(false)
            return
        }
        setLoading(true)
        fetch(`/app/api/ai/review?type=${activeTab}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setItems(data.items || [])
                setLoading(false)
            })
            .catch(() => setLoading(false))
    }, [activeTab, canView])

    useEffect(() => {
        if (typeof window === 'undefined') return
        const url = new URL(window.location.href)
        url.searchParams.set('tab', activeTab)
        window.history.replaceState(null, '', url.pathname + url.search)
    }, [activeTab])

    /** Group metadata candidates by field; section_header matches Insights metadata “Type” naming. */
    const categorySections = useMemo(() => {
        if (activeTab !== 'categories') {
            return []
        }
        const byFieldKey = new Map()
        for (const item of items) {
            const key = item.field_key || `unknown-${item.id}`
            if (!byFieldKey.has(key)) {
                byFieldKey.set(key, {
                    fieldKey: key,
                    sectionHeader: item.section_header || item.field_label || key,
                    rows: [],
                })
            }
            byFieldKey.get(key).rows.push(item)
        }
        return Array.from(byFieldKey.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const processingKey = (item) => {
        if (item.type === 'value_suggestion') return `vs-${item.id}`
        if (item.type === 'field_suggestion') return `fs-${item.id}`
        return String(item.id)
    }

    const handleApprove = async (item) => {
        const pk = processingKey(item)
        if (processing.has(pk)) return
        if (item.type === 'field_suggestion') {
            if (!canCreateField || !canAccept) return
        } else if (!canAccept) {
            return
        }
        setProcessing((p) => new Set(p).add(pk))
        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/accept`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/approve`
            } else if (item.type === 'value_suggestion') {
                url = `/app/api/ai/review/value-suggestions/${item.id}/accept`
            } else if (item.type === 'field_suggestion') {
                url = `/app/api/ai/review/field-suggestions/${item.id}/accept`
            } else {
                return
            }
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
            else if (res.status === 403 || res.status === 422) {
                const body = await res.json().catch(() => ({}))
                const msg = body.message || body.errors?.[Object.keys(body.errors || {})[0]]?.[0] || 'Action failed'
                window.alert(msg)
            }
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(pk)
                return next
            })
        }
    }

    const handleReject = async (item) => {
        const pk = processingKey(item)
        if (processing.has(pk) || !canReject) return
        setProcessing((p) => new Set(p).add(pk))
        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/dismiss`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/reject`
            } else if (item.type === 'value_suggestion') {
                url = `/app/api/ai/review/value-suggestions/${item.id}/reject`
            } else if (item.type === 'field_suggestion') {
                url = `/app/api/ai/review/field-suggestions/${item.id}/reject`
            } else {
                return
            }
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(pk)
                return next
            })
        }
    }

    const emptyLabel = () => {
        if (activeTab === 'values') return 'value'
        if (activeTab === 'fields') return 'field'
        return activeTab
    }

    if (!canView) {
        return (
            <InsightsLayout title="AI Review" activeSection="review">
                <div className="rounded-lg bg-amber-50 p-4 text-amber-800">
                    You do not have permission to view AI suggestions.
                </div>
            </InsightsLayout>
        )
    }

    return (
        <InsightsLayout title="AI Review" activeSection="review">
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">AI Review</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review and approve AI tag, category, and metadata suggestions.
                    </p>
                </div>

                <div className="border-b border-gray-200">
                    <nav className="-mb-px flex flex-wrap gap-4 lg:gap-6">
                        <button
                            type="button"
                            onClick={() => setActiveTab('tags')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'tags'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <TagIcon className="h-5 w-5" />
                            Tags
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('categories')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'categories'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <FolderIcon className="h-5 w-5" />
                            Categories
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('values')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'values'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <ListBulletIcon className="h-5 w-5" />
                            Values
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('fields')}
                            className={`flex items-center gap-2 border-b-2 py-3 text-sm font-medium ${
                                activeTab === 'fields'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <RectangleStackIcon className="h-5 w-5" />
                            Fields
                        </button>
                    </nav>
                </div>

                {loading ? (
                    <div className="rounded-lg bg-white p-8 text-center text-gray-500">Loading...</div>
                ) : items.length === 0 ? (
                    <div className="rounded-lg bg-white p-8 text-center">
                        <SparklesIcon className="mx-auto h-12 w-12 text-gray-300" />
                        <p className="mt-2 text-gray-500">No pending {emptyLabel()} suggestions.</p>
                    </div>
                ) : activeTab === 'tags' ? (
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <ul className="divide-y divide-gray-200">
                            {items.map((item) => (
                                <li key={item.id} className="flex items-center gap-4 p-4 hover:bg-gray-50">
                                    <div className="h-14 w-14 flex-shrink-0 overflow-hidden rounded bg-gray-100">
                                        {item.thumbnail_url ? (
                                            <img
                                                src={item.thumbnail_url}
                                                alt=""
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center text-gray-400">
                                                <SparklesIcon className="h-6 w-6" />
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-gray-900">{item.suggestion}</p>
                                        <p className="text-sm text-gray-500">
                                            {item.asset_title || item.asset_filename || 'Asset'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {item.confidence != null && (
                                            <span className="text-sm text-gray-500">
                                                {Math.round(item.confidence * 100)}%
                                            </span>
                                        )}
                                        {(canAccept || canReject) && (
                                            <>
                                                {canAccept && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleApprove(item)}
                                                        disabled={processing.has(processingKey(item))}
                                                        className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                                    >
                                                        <CheckIcon className="h-4 w-4" />
                                                        Accept
                                                    </button>
                                                )}
                                                {canReject && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleReject(item)}
                                                        disabled={processing.has(processingKey(item))}
                                                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                    >
                                                        <XMarkIcon className="h-4 w-4" />
                                                        Reject
                                                    </button>
                                                )}
                                            </>
                                        )}
                                        <Link
                                            href={`/app/assets?asset=${item.asset_id}`}
                                            className="text-gray-400 hover:text-indigo-600"
                                            title="Open in grid"
                                        >
                                            <ArrowTopRightOnSquareIcon className="h-5 w-5" />
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : activeTab === 'categories' ? (
                    <div className="space-y-6">
                        {categorySections.map((section) => (
                            <div
                                key={section.fieldKey}
                                className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                            >
                                <div className="border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                                    <h3 className="text-sm font-semibold text-gray-900">
                                        {section.sectionHeader}
                                        <span className="ml-2 font-normal text-gray-500">
                                            ({section.rows.length})
                                        </span>
                                    </h3>
                                </div>
                                <ul className="divide-y divide-gray-200">
                                    {section.rows.map((item) => (
                                        <li key={item.id} className="flex items-center gap-4 p-4 hover:bg-gray-50">
                                            <div className="h-14 w-14 flex-shrink-0 overflow-hidden rounded bg-gray-100">
                                                {item.thumbnail_url ? (
                                                    <img
                                                        src={item.thumbnail_url}
                                                        alt=""
                                                        className="h-full w-full object-cover"
                                                    />
                                                ) : (
                                                    <div className="flex h-full w-full items-center justify-center text-gray-400">
                                                        <SparklesIcon className="h-6 w-6" />
                                                    </div>
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-gray-900">{item.suggestion}</p>
                                                <p className="text-sm text-gray-500">
                                                    {item.asset_title || item.asset_filename || 'Asset'}
                                                    {(item.field_display_label || item.field_label) &&
                                                        ` • ${item.field_display_label || item.field_label}`}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {item.confidence != null && (
                                                    <span className="text-sm text-gray-500">
                                                        {Math.round(item.confidence * 100)}%
                                                    </span>
                                                )}
                                                {(canAccept || canReject) && (
                                                    <>
                                                        {canAccept && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleApprove(item)}
                                                                disabled={processing.has(processingKey(item))}
                                                                className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                                            >
                                                                <CheckIcon className="h-4 w-4" />
                                                                Accept
                                                            </button>
                                                        )}
                                                        {canReject && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleReject(item)}
                                                                disabled={processing.has(processingKey(item))}
                                                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                            >
                                                                <XMarkIcon className="h-4 w-4" />
                                                                Reject
                                                            </button>
                                                        )}
                                                    </>
                                                )}
                                                <Link
                                                    href={`/app/assets?asset=${item.asset_id}`}
                                                    className="text-gray-400 hover:text-indigo-600"
                                                    title="Open in grid"
                                                >
                                                    <ArrowTopRightOnSquareIcon className="h-5 w-5" />
                                                </Link>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                ) : activeTab === 'values' ? (
                    <div className="space-y-4">
                        {items.map((item) => (
                            <div
                                key={`vs-${item.id}`}
                                className="overflow-hidden rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                            >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 space-y-1">
                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                            Suggested value
                                        </p>
                                        <p className="text-lg font-semibold text-gray-900">{item.suggested_value}</p>
                                        <p className="text-sm text-gray-600">
                                            Field:{' '}
                                            <span className="font-medium text-gray-900">{item.field_label}</span>
                                            <span className="text-gray-400"> ({item.field_key})</span>
                                        </p>
                                        <p className="text-sm text-gray-500">
                                            Appears in:{' '}
                                            <span className="font-medium text-gray-800">
                                                {item.supporting_asset_count}{' '}
                                                {item.supporting_asset_count === 1 ? 'asset' : 'assets'}
                                            </span>
                                            {item.confidence != null && (
                                                <span className="ml-2">
                                                    · confidence {Math.round(item.confidence * 100)}%
                                                </span>
                                            )}
                                            {item.priority_score != null && (
                                                <span className="ml-2">
                                                    · priority {item.priority_score.toFixed(2)}
                                                </span>
                                            )}
                                        </p>
                                        {item.reason && (
                                            <p className="mt-2 border-l-2 border-indigo-200 pl-3 text-sm text-gray-600">
                                                {item.reason}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-shrink-0 gap-2">
                                        {canAccept && (
                                            <button
                                                type="button"
                                                onClick={() => handleApprove(item)}
                                                disabled={processing.has(processingKey(item))}
                                                className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                            >
                                                <CheckIcon className="h-4 w-4" />
                                                Add to field
                                            </button>
                                        )}
                                        {canReject && (
                                            <button
                                                type="button"
                                                onClick={() => handleReject(item)}
                                                disabled={processing.has(processingKey(item))}
                                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                            >
                                                <XMarkIcon className="h-4 w-4" />
                                                Reject
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-4">
                        {items.map((item) => (
                            <div
                                key={`fs-${item.id}`}
                                className="overflow-hidden rounded-lg border border-indigo-100 bg-gradient-to-br from-white to-indigo-50/40 p-5 shadow-sm"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                                    New field opportunity
                                </p>
                                <p className="mt-2 text-sm text-gray-700">
                                    <span className="font-medium text-gray-900">{item.source_cluster}</span>
                                    {' · '}
                                    {item.supporting_asset_count}{' '}
                                    {item.supporting_asset_count === 1 ? 'asset' : 'assets'} in this pattern
                                    {item.category_name && (
                                        <>
                                            {' '}
                                            · category{' '}
                                            <span className="font-medium">{item.category_name}</span>
                                        </>
                                    )}
                                    {item.confidence != null && (
                                        <span className="text-gray-500">
                                            {' '}
                                            · score {Math.round(item.confidence * 100)}%
                                        </span>
                                    )}
                                    {item.priority_score != null && (
                                        <span className="text-gray-500">
                                            {' '}
                                            · priority {item.priority_score.toFixed(2)}
                                        </span>
                                    )}
                                </p>
                                {item.reason && (
                                    <p className="mt-2 border-l-2 border-indigo-200 pl-3 text-sm text-gray-600">
                                        {item.reason}
                                    </p>
                                )}
                                <p className="mt-3 text-sm text-gray-500">Suggested</p>
                                <p className="text-lg font-semibold text-gray-900">{item.field_name}</p>
                                <p className="text-xs text-gray-400">Key: {item.field_key}</p>
                                <div className="mt-4">
                                    <p className="text-sm font-medium text-gray-700">Options</p>
                                    <ul className="mt-2 list-inside list-disc space-y-1 text-sm text-gray-800">
                                        {(item.suggested_options || []).map((opt) => (
                                            <li key={opt}>{opt}</li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="mt-5 flex flex-wrap gap-2">
                                    {canAccept && canCreateField && (
                                        <button
                                            type="button"
                                            onClick={() => handleApprove(item)}
                                            disabled={processing.has(processingKey(item))}
                                            className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                        >
                                            <CheckIcon className="h-4 w-4" />
                                            Create field
                                        </button>
                                    )}
                                    {canAccept && !canCreateField && (
                                        <p className="text-sm text-amber-700">
                                            Creating fields requires tenant metadata field permission.
                                        </p>
                                    )}
                                    {canReject && (
                                        <button
                                            type="button"
                                            onClick={() => handleReject(item)}
                                            disabled={processing.has(processingKey(item))}
                                            className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            <XMarkIcon className="h-4 w-4" />
                                            Reject
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}
