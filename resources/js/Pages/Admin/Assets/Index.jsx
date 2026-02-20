import { Head, Link, router } from '@inertiajs/react'
import { useState, useCallback, useRef, useEffect } from 'react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AssetDetailModal from '../../../Components/Admin/AssetDetailModal'
import {
    PhotoIcon,
    MagnifyingGlassIcon,
    FunnelIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    XMarkIcon,
    ArrowPathIcon,
    TrashIcon,
    ArrowUturnLeftIcon,
    DocumentDuplicateIcon,
    TagIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArchiveBoxIcon,
    TicketIcon,
    ArrowDownTrayIcon,
    WrenchScrewdriverIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'

const STATUS_COLORS = {
    complete: 'bg-emerald-100 text-emerald-800',
    completed: 'bg-emerald-100 text-emerald-800',
    uploading: 'bg-amber-100 text-amber-800',
    generating_thumbnails: 'bg-blue-100 text-blue-800',
    extracting_metadata: 'bg-blue-100 text-blue-800',
    generating_embedding: 'bg-blue-100 text-blue-800',
    scoring: 'bg-blue-100 text-blue-800',
    failed: 'bg-red-100 text-red-800',
    unknown: 'bg-slate-100 text-slate-800',
}

function parseSmartFilter(search) {
    const filters = {}
    let plain = search
    const patterns = [
        [/tenant:(\d+)/gi, 'tenant_id', (v) => parseInt(v, 10)],
        [/brand:(\d+)/gi, 'brand_id', (v) => parseInt(v, 10)],
        [/brand:([a-z0-9_-]+)/gi, 'brand_slug', String],
        [/status:(\w+)/gi, 'status', String],
        [/type:(asset|deliverable|ai_generated|execution|generative)/gi, 'asset_type', (v) => {
            const map = { execution: 'deliverable', generative: 'ai_generated', asset: 'asset', basic: 'asset' }
            return map[v?.toLowerCase()] ?? v
        }],
        [/analysis:(\w+)/gi, 'analysis_status', String],
        [/thumb:(\w+)/gi, 'thumbnail_status', String],
        [/incident:(true|false|1|0)/gi, 'has_incident', (v) => ['true', '1'].includes(String(v).toLowerCase())],
        [/visible:(true|false|1|0)/gi, 'visible_in_grid', (v) => {
            const s = String(v).toLowerCase()
            if (['true', '1'].includes(s)) return true
            if (['false', '0'].includes(s)) return false
            return undefined
        }],
        [/tag:([a-z0-9_-]+)/gi, 'tag', String],
        [/category:(\w+)/gi, 'category_slug', String],
        [/user:(\d+)/gi, 'created_by', (v) => parseInt(v, 10)],
        [/deleted:(true|false|1|0)/gi, 'deleted', (v) => ['true', '1'].includes(String(v).toLowerCase())],
    ]
    for (const [regex, key, fn] of patterns) {
        const m = search.match(regex)
        if (m) {
            const val = m[0].split(':')[1]
            filters[key] = fn(val)
            plain = plain.replace(regex, '').trim()
        }
    }
    return { plainSearch: plain.replace(/\s+/g, ' ').trim(), filters }
}

function buildQueryParams(filters, overrides = {}) {
    const q = { ...filters, ...overrides }
    const out = {}
    for (const [k, v] of Object.entries(q)) {
        if (v !== null && v !== undefined && v !== '') out[k] = v
    }
    return out
}

function formatDateTime(isoString) {
    if (!isoString) return '—'
    const d = new Date(isoString)
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
}

function SortableTh({ label, sortKey, currentSort, sortDirection, onSort, className = '' }) {
    const isActive = currentSort === sortKey
    const handleClick = () => {
        const nextDir = isActive && sortDirection === 'desc' ? 'asc' : 'desc'
        onSort(sortKey, nextDir)
    }
    return (
        <th className={`px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase cursor-pointer hover:bg-slate-100 select-none ${className}`} onClick={handleClick}>
            <span className="inline-flex items-center gap-1">
                {label}
                {isActive ? (
                    sortDirection === 'asc' ? (
                        <ChevronUpIcon className="h-4 w-4 text-indigo-600" aria-hidden />
                    ) : (
                        <ChevronDownIcon className="h-4 w-4 text-indigo-600" aria-hidden />
                    )
                ) : (
                    <ChevronDownIcon className="h-4 w-4 text-slate-300" aria-hidden />
                )}
            </span>
        </th>
    )
}

export default function AdminAssetsIndex({
    auth,
    assets,
    pagination,
    totalMatching,
    filters: initialFilters,
    filterOptions,
    canDestructive,
    assetsWithoutCategoryCount = 0,
    categoriesForRecovery = [],
}) {
    const [searchInput, setSearchInput] = useState(() => initialFilters?.search ?? '')
    const [advancedOpen, setAdvancedOpen] = useState(false)
    const [selectedIds, setSelectedIds] = useState(new Set())
    const [selectAllMatching, setSelectAllMatching] = useState(false)
    const [detailAsset, setDetailAsset] = useState(null)
    const [detailLoading, setDetailLoading] = useState(false)
    const [bulkLoading, setBulkLoading] = useState(false)
    const [actionsOpen, setActionsOpen] = useState(false)
    const [recoverCategoryModalOpen, setRecoverCategoryModalOpen] = useState(false)
    const [recoverCategoryId, setRecoverCategoryId] = useState('')
    const [recoverCategoryLoading, setRecoverCategoryLoading] = useState(false)
    const actionsDropdownRef = useRef(null)

    useEffect(() => {
        if (!actionsOpen) return
        const handleClickOutside = (e) => {
            if (actionsDropdownRef.current && !actionsDropdownRef.current.contains(e.target)) {
                setActionsOpen(false)
            }
        }
        document.addEventListener('click', handleClickOutside)
        return () => document.removeEventListener('click', handleClickOutside)
    }, [actionsOpen])

    const applyFilters = useCallback((overrides) => {
        const { plainSearch, filters: parsed } = parseSmartFilter(searchInput)
        const merged = buildQueryParams({ ...initialFilters, ...parsed, search: plainSearch }, overrides)
        router.get('/app/admin/assets', merged, { preserveState: true, preserveScroll: true })
    }, [searchInput, initialFilters])

    const handleSearchSubmit = (e) => {
        e?.preventDefault()
        applyFilters({ page: 1 })
    }

    const handleSort = (sortKey, sortDirection) => {
        applyFilters({ sort: sortKey, sort_direction: sortDirection, page: 1 })
    }

    const hasActiveFilters = !!(
        initialFilters?.asset_id ||
        initialFilters?.search ||
        initialFilters?.tenant_id ||
        initialFilters?.brand_id ||
        initialFilters?.status ||
        initialFilters?.asset_type ||
        initialFilters?.analysis_status ||
        initialFilters?.thumbnail_status ||
        initialFilters?.visible_in_grid != null ||
        initialFilters?.deleted != null ||
        initialFilters?.has_incident != null ||
        initialFilters?.storage_missing ||
        initialFilters?.category_id ||
        initialFilters?.tag ||
        initialFilters?.date_from ||
        initialFilters?.date_to
    )

    const clearFilters = () => {
        setSearchInput('')
        setSelectedIds(new Set())
        setSelectAllMatching(false)
        router.get('/app/admin/assets', {}, { preserveState: true, preserveScroll: true })
    }

    const openDetail = (asset) => {
        setDetailAsset(null)
        setDetailLoading(true)
        axios.get(`/app/admin/assets/${asset.id}`)
            .then((r) => setDetailAsset(r.data))
            .finally(() => setDetailLoading(false))
    }

    const closeDetail = () => setDetailAsset(null)

    const toggleSelect = (id) => {
        setSelectedIds((prev) => {
            const next = new Set(prev)
            if (next.has(id)) next.delete(id)
            else next.add(id)
            return next
        })
    }

    const selectPage = () => {
        const ids = assets.map((a) => a.id)
        setSelectedIds(new Set(ids))
        setSelectAllMatching(false)
    }

    const selectAllMatchingClick = () => {
        setSelectAllMatching(true)
        setSelectedIds(new Set(assets.map((a) => a.id)))
    }

    const clearSelection = () => {
        setSelectedIds(new Set())
        setSelectAllMatching(false)
    }

    const runBulkAction = async (action) => {
        if (action === 'export_ids') {
            setBulkLoading(true)
            try {
                const payload = {
                    action: 'export_ids',
                    asset_ids: selectAllMatching ? [] : Array.from(selectedIds),
                    select_all_matching: selectAllMatching,
                    filters: selectAllMatching ? buildQueryParams(initialFilters) : {},
                }
                const res = await axios.post('/app/admin/assets/bulk-action', payload)
                const ids = (res.data?.asset_ids ?? []).join('\n')
                await navigator.clipboard?.writeText(ids)
                alert(`${res.data?.count ?? 0} IDs copied to clipboard`)
            } catch (e) {
                alert(e?.response?.data?.error || e?.message || 'Export failed')
            } finally {
                setBulkLoading(false)
            }
            return
        }
        setBulkLoading(true)
        try {
            const payload = {
                action,
                asset_ids: selectAllMatching ? [] : Array.from(selectedIds),
                select_all_matching: selectAllMatching,
                filters: selectAllMatching ? buildQueryParams(initialFilters) : {},
            }
            const res = await axios.post('/app/admin/assets/bulk-action', payload)
            if (res.data?.success_count > 0) {
                clearSelection()
                router.reload()
            }
            if (res.data?.failed_count > 0) {
                alert(`${res.data.failed_count} failed. Check console.`)
            }
        } catch (e) {
            alert(e?.response?.data?.error || e?.message || 'Bulk action failed')
        } finally {
            setBulkLoading(false)
        }
    }

    const runSingleAction = async (assetId, action) => {
        try {
            if (action === 'repair') {
                await axios.post(`/app/admin/assets/${assetId}/repair`)
            } else if (action === 'restore') {
                await axios.post(`/app/admin/assets/${assetId}/restore`)
            } else if (action === 'retry-pipeline') {
                await axios.post(`/app/admin/assets/${assetId}/retry-pipeline`)
            } else if (action === 'reanalyze') {
                await axios.post(`/app/admin/assets/${assetId}/reanalyze`)
            }
            if (detailAsset?.asset?.id === assetId) {
                openDetail({ id: assetId })
            }
            router.reload()
        } catch (e) {
            alert(e?.response?.data?.error || e?.message || 'Action failed')
        }
    }

    const selectionCount = selectAllMatching ? totalMatching : selectedIds.size
    const hasSelection = selectionCount > 0

    const BULK_ACTIONS = [
        { id: 'restore', label: 'Restore', icon: ArrowUturnLeftIcon },
        { id: 'retry_pipeline', label: 'Force Retry Pipeline', icon: ArrowPathIcon },
        { id: 'regenerate_thumbnails', label: 'Regenerate Thumbnails', icon: PhotoIcon },
        { id: 'rerun_metadata', label: 'Re-run Metadata', icon: DocumentDuplicateIcon },
        { id: 'rerun_ai_tagging', label: 'Re-run AI Tagging', icon: TagIcon },
        { id: 'publish', label: 'Publish', icon: CheckCircleIcon },
        { id: 'unpublish', label: 'Unpublish', icon: XCircleIcon },
        { id: 'archive', label: 'Archive', icon: ArchiveBoxIcon },
        { id: 'clear_thumbnail_timeout', label: 'Clear Thumbnail Timeout Flag', icon: PhotoIcon },
        { id: 'clear_promotion_failed', label: 'Clear Promotion Failed', icon: CheckCircleIcon },
        { id: 'reconcile', label: 'Reconcile State', icon: WrenchScrewdriverIcon },
        { id: 'create_ticket', label: 'Create Support Ticket', icon: TicketIcon },
        { id: 'export_ids', label: 'Export IDs', icon: ArrowDownTrayIcon },
    ]
    if (canDestructive) {
        BULK_ACTIONS.push({ id: 'delete', label: 'Delete', icon: TrashIcon, destructive: true })
    }

    return (
        <div className="min-h-full bg-slate-50">
            <Head title="Asset Operations - Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Asset Operations</h1>
                        <p className="mt-1 text-sm text-slate-600">Cross-tenant search, repair, restore</p>
                    </div>
                    <Link
                        href="/app/admin"
                        className="text-sm text-slate-500 hover:text-slate-700"
                    >
                        ← Command Center
                    </Link>
                </div>

                {/* Warning: Assets without category (disappear from grid) */}
                {assetsWithoutCategoryCount > 0 && (
                    <div className="mb-4 rounded-lg border-2 border-amber-500 bg-amber-50 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-start gap-2">
                                <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-sm font-medium text-amber-900">
                                        {assetsWithoutCategoryCount} asset{assetsWithoutCategoryCount !== 1 ? 's' : ''} do not have a category and will not appear in the grid.
                                    </p>
                                    <p className="mt-1 text-xs text-amber-800">
                                        Assign a category to restore visibility. Only assets in the same brand as the chosen category will be updated.
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setRecoverCategoryModalOpen(true)}
                                className="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500"
                            >
                                <WrenchScrewdriverIcon className="h-4 w-4" />
                                Fix
                            </button>
                        </div>
                    </div>
                )}

                {/* Recover category modal */}
                {recoverCategoryModalOpen && (
                    <>
                        <div className="fixed inset-0 z-40 bg-slate-900/50" aria-hidden onClick={() => setRecoverCategoryModalOpen(false)} />
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                                <h3 className="text-lg font-semibold text-slate-900">Assign category to affected assets</h3>
                                <p className="mt-2 text-sm text-slate-600">
                                    Select a category. Only assets in the same brand as the category will be updated.
                                </p>
                                <div className="mt-4">
                                    <label className="block text-sm font-medium text-slate-700">Category</label>
                                    <select
                                        value={recoverCategoryId}
                                        onChange={(e) => setRecoverCategoryId(e.target.value)}
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                                    >
                                        <option value="">— Select —</option>
                                        {categoriesForRecovery.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name} ({c.brand_name})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="mt-6 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setRecoverCategoryModalOpen(false)}
                                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        disabled={!recoverCategoryId || recoverCategoryLoading}
                                        onClick={async () => {
                                            if (!recoverCategoryId) return
                                            setRecoverCategoryLoading(true)
                                            try {
                                                const { data } = await axios.post('/app/admin/assets/recover-category-id', {
                                                    category_id: parseInt(recoverCategoryId, 10),
                                                })
                                                setRecoverCategoryModalOpen(false)
                                                setRecoverCategoryId('')
                                                router.reload()
                                            } catch (err) {
                                                alert(err.response?.data?.error || 'Failed to recover')
                                            } finally {
                                                setRecoverCategoryLoading(false)
                                            }
                                        }}
                                        className="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500 disabled:opacity-50"
                                    >
                                        {recoverCategoryLoading ? 'Applying…' : 'Apply'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </>
                )}

                {/* Smart Filter Bar */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearchSubmit} className="flex-1 min-w-[280px] flex items-center gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 z-0" aria-hidden />
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(e) => setSearchInput(e.target.value)}
                                placeholder="Search assets... tenant:3 brand:augusta type:execution status:failed tag:whiskey"
                                className="relative z-10 block w-full rounded-lg border-slate-300 pl-10 pr-4 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Search
                        </button>
                    </form>
                    <button
                        type="button"
                        onClick={() => setAdvancedOpen(!advancedOpen)}
                        className={`inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium ${
                            advancedOpen ? 'bg-indigo-100 text-indigo-800' : 'bg-white border border-slate-300 text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        <FunnelIcon className="h-4 w-4" />
                        Filters
                    </button>
                    {hasActiveFilters && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
                            title="Clear all filters (including asset ID from URL)"
                        >
                            <XMarkIcon className="h-4 w-4" />
                            Clear all
                        </button>
                    )}
                </div>

                {/* Advanced Filter Drawer */}
                {advancedOpen && (
                    <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Tenant</label>
                                <select
                                    value={initialFilters?.tenant_id ?? ''}
                                    onChange={(e) => applyFilters({ tenant_id: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    {filterOptions?.tenants?.map((t) => (
                                        <option key={t.id} value={t.id}>{t.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Brand</label>
                                <select
                                    value={initialFilters?.brand_id ?? ''}
                                    onChange={(e) => applyFilters({ brand_id: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    {filterOptions?.brands?.map((b) => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Asset type</label>
                                <select
                                    value={initialFilters?.asset_type ?? ''}
                                    onChange={(e) => applyFilters({ asset_type: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="asset">Asset</option>
                                    <option value="deliverable">Execution</option>
                                    <option value="ai_generated">Generative</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Status</label>
                                <select
                                    value={initialFilters?.status ?? ''}
                                    onChange={(e) => applyFilters({ status: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="visible">Visible</option>
                                    <option value="hidden">Hidden</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Analysis</label>
                                <select
                                    value={initialFilters?.analysis_status ?? ''}
                                    onChange={(e) => applyFilters({ analysis_status: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="uploading">Uploading</option>
                                    <option value="complete">Complete</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Thumbnail</label>
                                <select
                                    value={initialFilters?.thumbnail_status ?? ''}
                                    onChange={(e) => applyFilters({ thumbnail_status: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="completed">Completed</option>
                                    <option value="failed">Failed</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Dead</label>
                                <select
                                    value={initialFilters?.storage_missing === true ? '1' : ''}
                                    onChange={(e) => applyFilters({ storage_missing: e.target.value === '1' ? true : null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="1">Dead (source missing)</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Incident</label>
                                <select
                                    value={initialFilters?.has_incident === true ? '1' : initialFilters?.has_incident === false ? '0' : ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ has_incident: v === '' ? null : v === '1', page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="1">Has incident</option>
                                    <option value="0">No incident</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Grid visibility</label>
                                <select
                                    value={initialFilters?.visible_in_grid === true ? '1' : initialFilters?.visible_in_grid === false ? '0' : ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ visible_in_grid: v === '' ? null : v === '1', page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="1">Visible in grid</option>
                                    <option value="0">Not visible</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Deleted</label>
                                <select
                                    value={initialFilters?.deleted === true ? '1' : initialFilters?.deleted === false ? '0' : ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ deleted: v === '' ? null : v === '1', page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="1">Deleted only</option>
                                    <option value="0">Not deleted</option>
                                </select>
                            </div>
                        </div>
                    </div>
                )}

                {/* Selection summary */}
                {assets?.length > 0 && (
                    <div className="mb-3 flex items-center gap-4 text-sm text-slate-600">
                        <span>{totalMatching?.toLocaleString() ?? 0} total</span>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={selectPage}
                                className="text-indigo-600 hover:text-indigo-800"
                            >
                                Select this page ({assets.length})
                            </button>
                            <span>·</span>
                            <button
                                type="button"
                                onClick={selectAllMatchingClick}
                                className="text-indigo-600 hover:text-indigo-800"
                            >
                                Select all {totalMatching?.toLocaleString() ?? 0} matching
                            </button>
                        </div>
                    </div>
                )}

                {/* Table */}
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="sticky top-0 z-10 bg-slate-50">
                                <tr>
                                    <th className="w-10 px-4 py-3 text-left">
                                        <input
                                            type="checkbox"
                                            checked={assets?.length > 0 && assets.every((a) => selectedIds.has(a.id) || selectAllMatching)}
                                            onChange={(e) => (e.target.checked ? selectPage() : clearSelection())}
                                            className="h-4 w-4 rounded border-slate-300 text-indigo-600"
                                        />
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">ID</th>
                                    <th className="w-16 px-2 py-3" />
                                    <SortableTh
                                        label="Filename"
                                        sortKey="filename"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                    />
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Tenant</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Brand</th>
                                    <SortableTh
                                        label="Analysis"
                                        sortKey="analysis_status"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                    />
                                    <SortableTh
                                        label="Thumb"
                                        sortKey="thumbnail_status"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                    />
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Incident</th>
                                    <SortableTh
                                        label="Created"
                                        sortKey="created_at"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                    />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {!assets?.length ? (
                                    <tr>
                                        <td colSpan={10} className="px-4 py-12 text-center text-slate-500">
                                            No assets found
                                        </td>
                                    </tr>
                                ) : (
                                    assets.map((a) => (
                                        <tr
                                            key={a.id}
                                            className="hover:bg-slate-50 cursor-pointer"
                                            onClick={() => openDetail(a)}
                                        >
                                            <td className="w-10 px-4 py-2" onClick={(e) => e.stopPropagation()}>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.has(a.id) || selectAllMatching}
                                                    onChange={() => toggleSelect(a.id)}
                                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600"
                                                />
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs text-slate-600" title={a.id}>{a.id_short}</td>
                                            <td className="w-16 px-2 py-2">
                                                {a.thumbnail_url ? (
                                                    <img src={a.thumbnail_url} alt="" className="h-10 w-10 object-cover rounded" />
                                                ) : (
                                                    <div className="h-10 w-10 rounded bg-slate-200 flex items-center justify-center">
                                                        <PhotoIcon className="h-5 w-5 text-slate-400" />
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-900 truncate max-w-[200px]" title={a.original_filename}>
                                                {a.original_filename || a.title || '—'}
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-600">{a.tenant?.name ?? '—'}</td>
                                            <td className="px-4 py-2 text-sm text-slate-600">{a.brand?.name ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                <span className={`inline-flex rounded px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[a.analysis_status] || STATUS_COLORS.unknown}`}>
                                                    {a.analysis_status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2">
                                                {a.storage_missing ? (
                                                    <span className="inline-flex rounded px-2 py-0.5 text-xs font-bold uppercase bg-red-600 text-white">
                                                        Dead
                                                    </span>
                                                ) : (
                                                    <span className={`inline-flex rounded px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[a.thumbnail_status] || STATUS_COLORS.unknown}`}>
                                                        {a.thumbnail_status}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {a.incident_count > 0 ? (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                                        <ExclamationTriangleIcon className="h-3.5 w-3.5" />
                                                        {a.incident_count}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-500 whitespace-nowrap" title={a.created_at}>
                                                {formatDateTime(a.created_at)}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination?.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                            <p className="text-sm text-slate-600">
                                Page {pagination.current_page} of {pagination.last_page}
                            </p>
                            <div className="flex gap-2">
                                {pagination.prev_page_url && (
                                    <button
                                        onClick={() => router.get(pagination.prev_page_url)}
                                        className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50"
                                    >
                                        Previous
                                    </button>
                                )}
                                {pagination.next_page_url && (
                                    <button
                                        onClick={() => router.get(pagination.next_page_url)}
                                        className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50"
                                    >
                                        Next
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                {/* Floating Bulk Action Bar */}
                {hasSelection && (
                    <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-4 rounded-xl border border-slate-200 bg-white px-6 py-3 shadow-lg">
                        <span className="text-sm font-medium text-slate-700">
                            {selectAllMatching ? `${totalMatching} selected` : `${selectedIds.size} selected`}
                        </span>
                        <div className="flex items-center gap-2">
                            <div className="relative" ref={actionsDropdownRef}>
                                <button
                                    type="button"
                                    disabled={bulkLoading}
                                    onClick={() => setActionsOpen((o) => !o)}
                                    className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Actions
                                    <ChevronDownIcon className="h-4 w-4" />
                                </button>
                                {actionsOpen && (
                                    <div className="absolute bottom-full left-0 mb-2 rounded-lg border border-slate-200 bg-white py-1 shadow-lg min-w-[220px] z-[60]">
                                        {BULK_ACTIONS.map((act) => (
                                            <button
                                                key={act.id}
                                                type="button"
                                                onClick={() => {
                                                    runBulkAction(act.id)
                                                    setActionsOpen(false)
                                                }}
                                                className={`flex w-full items-center gap-2 px-4 py-2 text-left text-sm hover:bg-slate-50 ${
                                                    act.destructive ? 'text-red-600' : 'text-slate-700'
                                                }`}
                                            >
                                                <act.icon className="h-4 w-4" />
                                                {act.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={clearSelection}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                )}
            </main>

            {/* Asset Detail Modal */}
            {(detailAsset !== null || detailLoading) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={closeDetail}>
                    <div
                        className="max-h-[90vh] w-full max-w-4xl overflow-auto rounded-xl bg-white shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {detailLoading ? (
                            <div className="p-12 text-center">Loading…</div>
                        ) : detailAsset ? (
                            <AssetDetailModal
                                data={detailAsset}
                                onClose={closeDetail}
                                onAction={runSingleAction}
                                onRefresh={() => { closeDetail(); router.reload() }}
                                showThumbnail
                            />
                        ) : null}
                    </div>
                </div>
            )}

            <AppFooter />
        </div>
    )
}
