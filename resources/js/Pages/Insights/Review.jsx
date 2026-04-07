import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import UploadApprovalsPanel from '../../Components/insights/UploadApprovalsPanel'
import {
    SparklesIcon,
    TagIcon,
    FolderIcon,
    CheckIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
    ListBulletIcon,
    RectangleStackIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    CloudArrowUpIcon,
} from '@heroicons/react/24/outline'
import { usePermission } from '../../hooks/usePermission'
import { InsightsBadge, useInsightsCounts } from '../../contexts/InsightsCountsContext'

const VALID_TABS = ['tags', 'categories', 'values', 'fields']
const PER_PAGE = 50

function PaginationBar({ pagination, loading, onPageChange }) {
    if (loading) return null
    const { total, last_page, per_page, current_page } = pagination
    if (last_page <= 1 && total <= per_page) return null
    const from = total === 0 ? 0 : (current_page - 1) * per_page + 1
    const to = Math.min(current_page * per_page, total)

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            <span>
                {total > 0 ? (
                    <>
                        Showing <span className="font-medium text-gray-900">{from}</span>–
                        <span className="font-medium text-gray-900">{to}</span> of{' '}
                        <span className="font-medium text-gray-900">{total}</span>
                    </>
                ) : (
                    'No results'
                )}
            </span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={current_page <= 1}
                    onClick={() => onPageChange((p) => Math.max(1, p - 1))}
                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <ChevronLeftIcon className="h-4 w-4" />
                    Previous
                </button>
                <span className="px-2 text-gray-500">
                    Page {current_page} of {last_page}
                </span>
                <button
                    type="button"
                    disabled={current_page >= last_page}
                    onClick={() => onPageChange((p) => p + 1)}
                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Next
                    <ChevronRightIcon className="h-4 w-4" />
                </button>
            </div>
        </div>
    )
}

function SectionBulkBar({
    sectionKeys,
    selected,
    onToggleSelectAll,
    onBulkAccept,
    onBulkReject,
    canAccept,
    canReject,
    processing,
}) {
    const allSelected = sectionKeys.length > 0 && sectionKeys.every((k) => selected.has(k))
    const numSelected = sectionKeys.filter((k) => selected.has(k)).length
    const selectedInSection = sectionKeys.filter((k) => selected.has(k))
    const someBusy = sectionKeys.some((k) => processing.has(k))

    const setIndeterminate = (el) => {
        if (el) el.indeterminate = numSelected > 0 && !allSelected
    }

    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                <input
                    ref={setIndeterminate}
                    type="checkbox"
                    checked={sectionKeys.length > 0 && allSelected}
                    onChange={onToggleSelectAll}
                    disabled={sectionKeys.length === 0 || someBusy}
                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                <span>
                    Select all ({sectionKeys.length})
                    {numSelected > 0 && <span className="text-gray-500"> · {numSelected} selected</span>}
                </span>
            </label>
            <div className="ml-auto flex flex-wrap gap-2">
                {canAccept && (
                    <button
                        type="button"
                        disabled={selectedInSection.length === 0 || someBusy}
                        onClick={() => onBulkAccept(selectedInSection)}
                        className="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <CheckIcon className="h-4 w-4" />
                        Accept selected ({selectedInSection.length})
                    </button>
                )}
                {canReject && (
                    <button
                        type="button"
                        disabled={selectedInSection.length === 0 || someBusy}
                        onClick={() => onBulkReject(selectedInSection)}
                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <XMarkIcon className="h-4 w-4" />
                        Reject selected ({selectedInSection.length})
                    </button>
                )}
            </div>
        </div>
    )
}

export default function AnalyticsReview({
    initialTab = 'tags',
    initialWorkspace = 'ai',
    initialApprovalQueue = 'team',
    canViewAi = false,
    canViewUploadApprovals = false,
    creatorModuleEnabled = false,
    canCreateFieldFromSuggestion = false,
}) {
    const [activeTab, setActiveTab] = useState(() =>
        VALID_TABS.includes(initialTab) ? initialTab : 'tags'
    )
    const [workspace, setWorkspace] = useState(() => (initialWorkspace === 'uploads' ? 'uploads' : 'ai'))
    const [approvalQueue, setApprovalQueue] = useState(() =>
        initialApprovalQueue === 'creator' ? 'creator' : 'team'
    )
    const [page, setPage] = useState(1)
    const [items, setItems] = useState([])
    const [pagination, setPagination] = useState({
        total: 0,
        current_page: 1,
        last_page: 1,
        per_page: PER_PAGE,
    })
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [selected, setSelected] = useState(() => new Set())
    const { can } = usePermission()
    const canAccept = can('metadata.suggestions.apply') || can('metadata.edit_post_upload')
    const canReject = can('metadata.suggestions.dismiss') || can('metadata.edit_post_upload')
    const canCreateField =
        canCreateFieldFromSuggestion ||
        can('metadata.tenant.field.create') ||
        can('metadata.tenant.field.manage')
    const insightsCounts = useInsightsCounts()

    useEffect(() => {
        if (VALID_TABS.includes(initialTab)) {
            setActiveTab(initialTab)
        }
    }, [initialTab])

    useEffect(() => {
        setWorkspace(initialWorkspace === 'uploads' ? 'uploads' : 'ai')
    }, [initialWorkspace])

    useEffect(() => {
        setApprovalQueue(initialApprovalQueue === 'creator' ? 'creator' : 'team')
    }, [initialApprovalQueue])

    useEffect(() => {
        setPage(1)
    }, [activeTab])

    useEffect(() => {
        setSelected(new Set())
    }, [activeTab, page])

    useEffect(() => {
        if (!canViewAi || workspace !== 'ai') {
            setLoading(false)
            return
        }
        setLoading(true)
        const params = new URLSearchParams({
            type: activeTab,
            page: String(page),
            per_page: String(PER_PAGE),
        })
        fetch(`/app/api/ai/review?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setItems(data.items || [])
                setPagination({
                    total: data.total ?? 0,
                    current_page: data.current_page ?? 1,
                    last_page: data.last_page ?? 1,
                    per_page: data.per_page ?? PER_PAGE,
                })
                setLoading(false)
            })
            .catch(() => setLoading(false))
    }, [activeTab, page, canViewAi, workspace])

    useEffect(() => {
        if (typeof window === 'undefined') return
        const url = new URL(window.location.href)
        if (workspace === 'uploads') {
            url.searchParams.set('workspace', 'uploads')
            url.searchParams.delete('tab')
            if (creatorModuleEnabled && canViewUploadApprovals) {
                if (approvalQueue === 'creator') {
                    url.searchParams.set('approval_queue', 'creator')
                } else {
                    url.searchParams.delete('approval_queue')
                }
            } else {
                url.searchParams.delete('approval_queue')
            }
        } else {
            url.searchParams.delete('workspace')
            url.searchParams.delete('approval_queue')
            url.searchParams.set('tab', activeTab)
        }
        window.history.replaceState(null, '', url.pathname + url.search)
    }, [workspace, activeTab, approvalQueue, creatorModuleEnabled, canViewUploadApprovals])

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

    const valueSections = useMemo(() => {
        if (activeTab !== 'values') return []
        const byField = new Map()
        for (const item of items) {
            const key = item.field_key || `unknown-${item.id}`
            if (!byField.has(key)) {
                byField.set(key, {
                    fieldKey: key,
                    sectionHeader: item.field_label || key,
                    rows: [],
                })
            }
            byField.get(key).rows.push(item)
        }
        return Array.from(byField.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const fieldSections = useMemo(() => {
        if (activeTab !== 'fields') return []
        const byCat = new Map()
        for (const item of items) {
            const key = item.category_slug || `unknown-${item.id}`
            if (!byCat.has(key)) {
                byCat.set(key, {
                    categorySlug: key,
                    sectionHeader: item.category_name || key,
                    rows: [],
                })
            }
            byCat.get(key).rows.push(item)
        }
        return Array.from(byCat.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const processingKey = useCallback((item) => {
        if (item.type === 'value_suggestion') return `vs-${item.id}`
        if (item.type === 'field_suggestion') return `fs-${item.id}`
        return String(item.id)
    }, [])

    const tagKeysOnPage = useMemo(() => items.map((i) => processingKey(i)), [items, processingKey])

    const toggleSelected = useCallback((pk) => {
        setSelected((prev) => {
            const next = new Set(prev)
            if (next.has(pk)) next.delete(pk)
            else next.add(pk)
            return next
        })
    }, [])

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
            if (res.ok) {
                setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
                insightsCounts?.reload?.()
            } else if (res.status === 403 || res.status === 422) {
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
            if (res.ok) {
                setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
                insightsCounts?.reload?.()
            }
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(pk)
                return next
            })
        }
    }

    const bulkApproveKeys = async (keys) => {
        const list = keys.map((pk) => items.find((i) => processingKey(i) === pk)).filter(Boolean)
        for (const item of list) {
            if (item.type === 'field_suggestion' && (!canCreateField || !canAccept)) continue
            if (item.type !== 'field_suggestion' && !canAccept) continue
            await handleApprove(item)
        }
    }

    const bulkRejectKeys = async (keys) => {
        if (!canReject) return
        const list = keys.map((pk) => items.find((i) => processingKey(i) === pk)).filter(Boolean)
        for (const item of list) {
            await handleReject(item)
        }
    }

    const toggleSelectAll = (sectionKeys) => {
        setSelected((prev) => {
            const next = new Set(prev)
            const allOn = sectionKeys.length > 0 && sectionKeys.every((k) => next.has(k))
            if (allOn) {
                sectionKeys.forEach((k) => next.delete(k))
            } else {
                sectionKeys.forEach((k) => next.add(k))
            }
            return next
        })
    }

    const emptyLabel = () => {
        if (activeTab === 'values') return 'value'
        if (activeTab === 'fields') return 'field'
        return activeTab
    }

    const renderSuggestionRow = (item, showCheckbox = true) => {
        const pk = processingKey(item)
        return (
            <li key={pk} className="flex items-center gap-4 p-4 hover:bg-gray-50">
                {showCheckbox && (canAccept || canReject) && (
                    <input
                        type="checkbox"
                        checked={selected.has(pk)}
                        onChange={() => toggleSelected(pk)}
                        disabled={processing.has(pk)}
                        className="h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                )}
                <div className="h-14 w-14 flex-shrink-0 overflow-hidden rounded bg-gray-100">
                    {item.thumbnail_url ? (
                        <img src={item.thumbnail_url} alt="" className="h-full w-full object-cover" />
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
                        <span className="text-sm text-gray-500">{Math.round(item.confidence * 100)}%</span>
                    )}
                    {(canAccept || canReject) && (
                        <>
                            {canAccept && (
                                <button
                                    type="button"
                                    onClick={() => handleApprove(item)}
                                    disabled={processing.has(pk)}
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
                                    disabled={processing.has(pk)}
                                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    <XMarkIcon className="h-4 w-4" />
                                    Reject
                                </button>
                            )}
                        </>
                    )}
                    <Link
                        href={`/app/assets?q=${encodeURIComponent(item.asset_id)}&asset=${encodeURIComponent(item.asset_id)}`}
                        className="text-gray-400 hover:text-indigo-600"
                        title="Open in grid"
                    >
                        <ArrowTopRightOnSquareIcon className="h-5 w-5" />
                    </Link>
                </div>
            </li>
        )
    }

    if (!canViewAi && !canViewUploadApprovals) {
        return (
            <InsightsLayout title="Review" activeSection="review">
                <div className="rounded-lg bg-amber-50 p-4 text-amber-800">
                    You do not have permission to view this page.
                </div>
            </InsightsLayout>
        )
    }

    const showWorkspaceToggle = canViewAi && canViewUploadApprovals

    return (
        <InsightsLayout title="Review" activeSection="review">
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Review</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Approve AI metadata suggestions and teammate uploads that require brand sign-off.
                    </p>
                </div>

                {showWorkspaceToggle ? (
                    <div className="flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                        <button
                            type="button"
                            onClick={() => setWorkspace('ai')}
                            className={`inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                workspace === 'ai'
                                    ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                    : 'text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            <SparklesIcon className="h-5 w-5" />
                            AI suggestions
                            {insightsCounts && insightsCounts.aiTotal > 0 && (
                                <InsightsBadge count={insightsCounts.aiTotal} />
                            )}
                        </button>
                        <button
                            type="button"
                            onClick={() => setWorkspace('uploads')}
                            className={`inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                workspace === 'uploads'
                                    ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                    : 'text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            <CloudArrowUpIcon className="h-5 w-5" />
                            Upload approvals
                            {insightsCounts && insightsCounts.uploadTotal > 0 && (
                                <InsightsBadge count={insightsCounts.uploadTotal} />
                            )}
                        </button>
                    </div>
                ) : null}

                {workspace === 'uploads' && canViewUploadApprovals && creatorModuleEnabled ? (
                    <div className="flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                        <button
                            type="button"
                            onClick={() => setApprovalQueue('team')}
                            className={`inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                approvalQueue === 'team'
                                    ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                    : 'text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            <CloudArrowUpIcon className="h-5 w-5" />
                            Team uploads
                            {insightsCounts && insightsCounts.uploadTeam > 0 && (
                                <InsightsBadge count={insightsCounts.uploadTeam} />
                            )}
                        </button>
                        <button
                            type="button"
                            onClick={() => setApprovalQueue('creator')}
                            className={`inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                approvalQueue === 'creator'
                                    ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                    : 'text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            <CloudArrowUpIcon className="h-5 w-5" />
                            Creator uploads
                            {insightsCounts && insightsCounts.uploadCreator > 0 && (
                                <InsightsBadge count={insightsCounts.uploadCreator} />
                            )}
                        </button>
                    </div>
                ) : null}

                {workspace === 'uploads' && canViewUploadApprovals ? (
                    <div className="space-y-4">
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">Upload approvals</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                {creatorModuleEnabled && approvalQueue === 'creator'
                                    ? 'Deliverables from your creator program pending brand review and publish.'
                                    : 'Assets from teammates pending approval (requires_approval workflow).'}
                            </p>
                        </div>
                        <UploadApprovalsPanel
                            queue={creatorModuleEnabled && approvalQueue === 'creator' ? 'creator' : 'team'}
                            onQueueChanged={() => insightsCounts?.reload?.()}
                        />
                    </div>
                ) : null}

                {workspace === 'ai' && canViewAi ? (
            <>
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
                            {insightsCounts && insightsCounts.tags > 0 && (
                                <InsightsBadge count={insightsCounts.tags} />
                            )}
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
                            {insightsCounts && insightsCounts.categories > 0 && (
                                <InsightsBadge count={insightsCounts.categories} />
                            )}
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
                            {insightsCounts && insightsCounts.values > 0 && (
                                <InsightsBadge count={insightsCounts.values} />
                            )}
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
                            {insightsCounts && insightsCounts.fields > 0 && (
                                <InsightsBadge count={insightsCounts.fields} />
                            )}
                        </button>
                    </nav>
                </div>

                {loading ? (
                    <div className="rounded-lg bg-white p-8 text-center text-gray-500">Loading...</div>
                ) : items.length === 0 ? (
                    <div className="rounded-lg bg-white p-8 text-center">
                        <SparklesIcon className="mx-auto h-12 w-12 text-gray-300" />
                        {pagination.total > 0 && page > 1 ? (
                            <p className="mt-2 text-gray-600">
                                No items on this page.{' '}
                                <button
                                    type="button"
                                    className="font-medium text-indigo-600 hover:text-indigo-800 hover:underline"
                                    onClick={() => setPage(1)}
                                >
                                    Go to first page
                                </button>
                            </p>
                        ) : (
                            <p className="mt-2 text-gray-500">No pending {emptyLabel()} suggestions.</p>
                        )}
                    </div>
                ) : activeTab === 'tags' ? (
                    <div className="space-y-3">
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <SectionBulkBar
                                sectionKeys={tagKeysOnPage}
                                selected={selected}
                                onToggleSelectAll={() => toggleSelectAll(tagKeysOnPage)}
                                onBulkAccept={bulkApproveKeys}
                                onBulkReject={bulkRejectKeys}
                                canAccept={canAccept}
                                canReject={canReject}
                                processing={processing}
                            />
                            <ul className="divide-y divide-gray-200">{items.map((item) => renderSuggestionRow(item))}</ul>
                        </div>
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : activeTab === 'categories' ? (
                    <div className="space-y-6">
                        {categorySections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.fieldKey}
                                    className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                >
                                    <div className="border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                                        <h3 className="text-sm font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-gray-200">
                                        {section.rows.map((item) => renderSuggestionRow(item))}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : activeTab === 'values' ? (
                    <div className="space-y-6">
                        {valueSections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.fieldKey}
                                    className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                >
                                    <div className="border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                                        <h3 className="text-sm font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-gray-200">
                                        {section.rows.map((item) => (
                                            <li
                                                key={processingKey(item)}
                                                className="flex flex-col gap-4 p-5 hover:bg-gray-50 sm:flex-row sm:items-start sm:justify-between"
                                            >
                                                <div className="flex min-w-0 flex-1 gap-4">
                                                    {(canAccept || canReject) && (
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.has(processingKey(item))}
                                                            onChange={() => toggleSelected(processingKey(item))}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                        />
                                                    )}
                                                    <div className="min-w-0 space-y-1">
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                            Suggested value
                                                        </p>
                                                        <p className="text-lg font-semibold text-gray-900">
                                                            {item.suggested_value}
                                                        </p>
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
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : (
                    <div className="space-y-6">
                        {fieldSections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.categorySlug}
                                    className="overflow-hidden rounded-lg border border-indigo-100 bg-gradient-to-br from-white to-indigo-50/40 shadow-sm"
                                >
                                    <div className="border-b border-indigo-100 bg-white/80 px-4 py-2.5">
                                        <h3 className="text-sm font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept && canCreateField}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-indigo-100/80">
                                        {section.rows.map((item) => (
                                            <li key={processingKey(item)} className="p-5">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                                                    {(canAccept || canReject) && (
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.has(processingKey(item))}
                                                            onChange={() => toggleSelected(processingKey(item))}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                        />
                                                    )}
                                                    <div className="min-w-0 flex-1">
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
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                )}
            </>
                ) : null}

            </div>
        </InsightsLayout>
    )
}
