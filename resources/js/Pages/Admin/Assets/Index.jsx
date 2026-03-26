import { Link, router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import { useState, useCallback, useRef, useEffect, useMemo } from 'react'
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
import { CheckIcon } from '@heroicons/react/20/solid'

/** Admin list: where this asset sits vs library grid / canvas / generative (see AdminAssetController::adminAssetRowContext) */
const ROW_CONTEXT_BADGES = {
    library: 'bg-slate-100 text-slate-700 ring-1 ring-slate-200/90',
    composition_canvas: 'bg-violet-50 text-violet-900 ring-1 ring-violet-200/90',
    generative: 'bg-sky-50 text-sky-900 ring-1 ring-sky-200/90',
    reference: 'bg-amber-50 text-amber-900 ring-1 ring-amber-200/90',
}

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
        [/builder:(true|false|1|0)/gi, 'builder_staged', (v) => {
            const s = String(v).toLowerCase()
            if (['true', '1'].includes(s)) return true
            if (['false', '0'].includes(s)) return false
            return undefined
        }],
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
        if (v === null || v === undefined || v === '') continue
        if (k === 'types' && Array.isArray(v)) {
            out[k] = v.join(',')
            continue
        }
        out[k] = v
    }
    return out
}

function formatFileSize(bytes) {
    if (bytes == null || bytes < 0 || Number.isNaN(bytes)) return null
    const u = ['B', 'KB', 'MB', 'GB', 'TB']
    let n = Number(bytes)
    let i = 0
    while (n >= 1024 && i < u.length - 1) {
        n /= 1024
        i += 1
    }
    const digits = i === 0 ? 0 : n >= 10 ? 1 : 2
    return `${n.toFixed(digits)} ${u[i]}`
}

/** Default admin grid: standard assets + executions, no generative / composition-tagged rows */
function isDefaultPrimaryTypeScope(f) {
    if (!f || f.types === 'all') return false
    const t = f.types
    if (!Array.isArray(t) || t.length !== 2) return false
    const sorted = [...t].map(String).sort()
    return (
        sorted[0] === 'asset'
        && sorted[1] === 'deliverable'
        && f.include_composition === false
        && f.composition_only !== true
    )
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
        const base = { ...initialFilters, ...parsed, search: plainSearch }
        const merged = { ...base, ...overrides }
        if (Object.prototype.hasOwnProperty.call(overrides, 'asset_type') && overrides.asset_type === null) {
            delete merged.asset_type
            // Do not wipe types/composition when this request explicitly sets them (e.g. faceted toggles).
            if (!Object.prototype.hasOwnProperty.call(overrides, 'types')) {
                delete merged.types
            }
            if (!Object.prototype.hasOwnProperty.call(overrides, 'composition') && !Object.prototype.hasOwnProperty.call(overrides, 'include_composition')) {
                delete merged.include_composition
            }
        }
        if (overrides.asset_type) {
            delete merged.types
            delete merged.include_composition
            delete merged.composition_only
        }
        const params = buildQueryParams(merged, {})
        router.get('/app/admin/assets', params, { preserveState: true, preserveScroll: true })
    }, [searchInput, initialFilters])

    const primaryTypeState = useMemo(() => {
        const f = initialFilters
        if (f?.types === 'all') {
            return {
                mode: 'all',
                asset: true,
                execution: true,
                generative: true,
                composition: true,
                compositionOnly: false,
            }
        }
        const types = Array.isArray(f?.types) ? f.types : []
        if (types.length > 0) {
            return {
                mode: 'custom',
                asset: types.includes('asset'),
                execution: types.includes('deliverable'),
                generative: types.includes('ai_generated'),
                composition: f?.include_composition === true,
                compositionOnly: f?.composition_only === true,
            }
        }
        // Smart search / advanced dropdown can set asset_type alone — mirror that in toggles
        if (f?.asset_type) {
            const at = f.asset_type
            return {
                mode: 'custom',
                asset: at === 'asset',
                execution: at === 'deliverable',
                generative: at === 'ai_generated',
                composition: f?.include_composition === true,
                compositionOnly: f?.composition_only === true,
            }
        }
        return {
            mode: 'custom',
            asset: true,
            execution: true,
            generative: false,
            composition: f?.include_composition === true,
            compositionOnly: f?.composition_only === true,
        }
    }, [initialFilters])

    const applyPrimaryTypeToggles = useCallback((next) => {
        if (next.mode === 'all') {
            applyFilters({ asset_type: null, types: 'all', composition_only: false, page: 1 })
            return
        }
        const parts = []
        if (next.asset) parts.push('asset')
        if (next.execution) parts.push('deliverable')
        if (next.generative) parts.push('ai_generated')
        if (parts.length === 0) {
            parts.push('asset', 'deliverable')
        }
        applyFilters({
            asset_type: null,
            types: parts.join(','),
            composition: next.composition ? '1' : '0',
            composition_only: next.compositionOnly ? '1' : '0',
            page: 1,
        })
    }, [applyFilters])

    /** One-click views: library = what members usually see in the asset grid; canvas = composition WIP/preview only; generative = AI outputs */
    const applyViewPreset = useCallback(
        (preset) => {
            if (preset === 'all') {
                applyPrimaryTypeToggles({ mode: 'all' })
                return
            }
            if (preset === 'library') {
                applyFilters({
                    asset_type: null,
                    types: ['asset', 'deliverable'],
                    composition: '0',
                    composition_only: '0',
                    page: 1,
                })
                return
            }
            if (preset === 'canvas') {
                applyFilters({
                    asset_type: null,
                    types: ['asset', 'deliverable', 'ai_generated'],
                    composition: '1',
                    composition_only: '1',
                    page: 1,
                })
                return
            }
            if (preset === 'generative') {
                applyFilters({
                    asset_type: null,
                    types: ['ai_generated'],
                    composition: '0',
                    composition_only: '0',
                    page: 1,
                })
            }
        },
        [applyFilters, applyPrimaryTypeToggles],
    )

    const togglePrimaryType = useCallback(
        (key) => {
            if (key === 'compositionOnly') {
                const base =
                    primaryTypeState.mode === 'all'
                        ? {
                            mode: 'custom',
                            asset: true,
                            execution: true,
                            generative: true,
                            composition: true,
                            compositionOnly: false,
                        }
                        : { ...primaryTypeState, mode: 'custom' }
                const next = { ...base, compositionOnly: !base.compositionOnly }
                if (next.compositionOnly) {
                    next.composition = true
                }
                applyPrimaryTypeToggles(next)
                return
            }
            const base =
                primaryTypeState.mode === 'all'
                    ? {
                        mode: 'custom',
                        asset: true,
                        execution: true,
                        generative: true,
                        composition: true,
                        compositionOnly: false,
                    }
                    : { ...primaryTypeState, mode: 'custom' }
            const next = { ...base, [key]: !base[key] }
            if (key === 'composition' && !next.composition) {
                next.compositionOnly = false
            }
            if (!next.asset && !next.execution && !next.generative) {
                next.asset = true
                next.execution = true
            }
            applyPrimaryTypeToggles(next)
        },
        [applyPrimaryTypeToggles, primaryTypeState]
    )

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
        initialFilters?.builder_staged != null ||
        initialFilters?.intake_state ||
        initialFilters?.deleted != null ||
        initialFilters?.has_incident != null ||
        initialFilters?.storage_missing ||
        initialFilters?.category_id ||
        initialFilters?.tag ||
        initialFilters?.date_from ||
        initialFilters?.date_to ||
        initialFilters?.types === 'all' ||
        (initialFilters?.types && !isDefaultPrimaryTypeScope(initialFilters))
        || initialFilters?.composition_only === true
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
            <AppHead title="Asset Operations" suffix="Admin" />
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

                {/* Warning: Library assets (asset + execution) missing category — generative / reference / composition excluded */}
                {assetsWithoutCategoryCount > 0 && (
                    <div className="mb-4 rounded-lg border-2 border-amber-500 bg-amber-50 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-start gap-2">
                                <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-sm font-medium text-amber-900">
                                        {assetsWithoutCategoryCount} library asset{assetsWithoutCategoryCount !== 1 ? 's' : ''} (standard + execution) {assetsWithoutCategoryCount !== 1 ? 'are' : 'is'} missing a category and will not appear in the main grid.
                                    </p>
                                    <p className="mt-1 text-xs text-amber-800">
                                        AI generative, reference, and composition (WIP/preview) assets are excluded — they are not expected to use the same category rules. Assign a category to fix visibility; only assets in the same brand as the chosen category are updated.
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
                                <h3 className="text-lg font-semibold text-slate-900">Assign category to affected library assets</h3>
                                <p className="mt-2 text-sm text-slate-600">
                                    Applies to standard and execution assets missing <code className="text-xs bg-slate-100 px-1 rounded">category_id</code>. Generative, reference, and composition-tagged assets are not modified.
                                </p>
                                <p className="mt-2 text-sm text-slate-600">
                                    Only assets in the same brand as the selected category will be updated.
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
                                placeholder="Search assets... tenant:3 brand:augusta type:execution builder:true tag:whiskey"
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

                {/* Primary type scope — library `type` column vs composition metadata (orthogonal) */}
                <div className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Show in grid</span>
                        {initialFilters?.asset_type && !Array.isArray(initialFilters?.types) && (
                            <span className="text-xs text-indigo-800 bg-indigo-50 border border-indigo-100 rounded-md px-2 py-0.5">
                                Narrowed by asset type elsewhere — click a facet to use quick filters instead
                            </span>
                        )}
                    </div>

                    <p className="mt-2 text-xs text-slate-600 leading-relaxed max-w-4xl">
                        Compositions are saved canvases; each still stores files as normal <strong className="text-slate-800">assets</strong> with extra metadata.
                        Most visits here are for <strong className="text-slate-800">library + execution</strong> rows that match what appears in the brand asset grid.
                        Canvas exports and many generative layers are usually out of the normal grid—use quick views or the <strong className="text-slate-800">Context</strong> column instead of mixing them blindly.
                    </p>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Quick view</span>
                        {[
                            { id: 'library', label: 'Library + executions' },
                            { id: 'canvas', label: 'Canvas exports only' },
                            { id: 'generative', label: 'Generative only' },
                            { id: 'all', label: 'All types' },
                        ].map(({ id, label }) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => applyViewPreset(id)}
                                className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 hover:border-slate-300"
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <div className="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-8">
                        <div className="min-w-0 flex-1">
                            <p className="text-[11px] font-medium text-slate-600 mb-2">Library type</p>
                            <p className="text-[10px] text-slate-400 mb-2 leading-snug max-w-xl">
                                DB <span className="text-slate-500">type</span> on each row: file, execution deliverable, or AI output. Combine types; this is separate from the composition canvas flags below.
                            </p>
                            <div
                                className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner"
                                role="group"
                                aria-label="Filter by library asset type"
                            >
                                {[
                                    { key: 'asset', label: 'Asset' },
                                    { key: 'execution', label: 'Execution' },
                                    { key: 'generative', label: 'AI generative' },
                                ].map(({ key, label }) => {
                                    const active = primaryTypeState.mode === 'all' || primaryTypeState[key]
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => togglePrimaryType(key)}
                                            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                                active
                                                    ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                                    : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                            }`}
                                        >
                                            <span
                                                className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border ${
                                                    active ? 'border-indigo-500 bg-indigo-600' : 'border-slate-300 bg-white'
                                                }`}
                                                aria-hidden
                                            >
                                                {active && <CheckIcon className="h-2.5 w-2.5 text-white" aria-hidden />}
                                            </span>
                                            {label}
                                        </button>
                                    )
                                })}
                                <span className="mx-0.5 h-5 w-px shrink-0 bg-slate-300/90" aria-hidden />
                                <button
                                    type="button"
                                    aria-pressed={primaryTypeState.mode === 'all'}
                                    onClick={() => applyPrimaryTypeToggles({ mode: 'all' })}
                                    className={`inline-flex items-center rounded-md px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                        primaryTypeState.mode === 'all'
                                            ? 'bg-indigo-600 text-white shadow-sm ring-1 ring-indigo-700/20'
                                            : 'text-slate-600 hover:bg-white/70 hover:text-slate-900'
                                    }`}
                                >
                                    All types
                                </button>
                            </div>
                        </div>

                        <div className="lg:border-l lg:border-slate-200 lg:pl-8 lg:min-w-[220px]">
                            <p className="text-[11px] font-medium text-slate-600 mb-2">Composition workflow</p>
                            <p className="text-[10px] text-slate-400 mb-2 leading-snug max-w-sm">
                                Not a separate DB type — canvas WIP/preview exports are still normal assets with extra metadata. By default those rows are hidden so the grid stays clean.
                            </p>
                            <div
                                className="flex flex-col gap-2"
                                role="group"
                                aria-label="Composition workflow filters"
                            >
                                <div className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner">
                                    <button
                                        type="button"
                                        aria-pressed={primaryTypeState.mode === 'all' || primaryTypeState.composition}
                                        onClick={() => togglePrimaryType('composition')}
                                        className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                            primaryTypeState.mode === 'all' || primaryTypeState.composition
                                                ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                                : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                        }`}
                                    >
                                        <span
                                            className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border ${
                                                primaryTypeState.mode === 'all' || primaryTypeState.composition
                                                    ? 'border-indigo-500 bg-indigo-600'
                                                    : 'border-slate-300 bg-white'
                                            }`}
                                            aria-hidden
                                        >
                                            {(primaryTypeState.mode === 'all' || primaryTypeState.composition) && (
                                                <CheckIcon className="h-2.5 w-2.5 text-white" aria-hidden />
                                            )}
                                        </span>
                                        Include composition-tagged
                                    </button>
                                </div>
                                <div className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner">
                                    <button
                                        type="button"
                                        aria-pressed={primaryTypeState.compositionOnly}
                                        onClick={() => togglePrimaryType('compositionOnly')}
                                        title="Hide non-composition library rows; still uses Library type checkboxes above"
                                        className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                            primaryTypeState.compositionOnly
                                                ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                                : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                        }`}
                                    >
                                        <span
                                            className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border ${
                                                primaryTypeState.compositionOnly
                                                    ? 'border-indigo-500 bg-indigo-600'
                                                    : 'border-slate-300 bg-white'
                                            }`}
                                            aria-hidden
                                        >
                                            {primaryTypeState.compositionOnly && (
                                                <CheckIcon className="h-2.5 w-2.5 text-white" aria-hidden />
                                            )}
                                        </span>
                                        Only composition-tagged
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p className="mt-3 text-xs text-slate-500 border-t border-slate-100 pt-3">
                        Default: <strong className="text-slate-700">Asset</strong> + <strong className="text-slate-700">Execution</strong>, composition-tagged rows <strong className="text-slate-700">hidden</strong>.
                        {' '}
                        <strong className="text-slate-700">Include</strong> adds them next to your other files.
                        {' '}
                        <strong className="text-slate-700">Only composition-tagged</strong> narrows to canvas WIP/preview rows (still filtered by library types above).
                    </p>
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
                                    onChange={(e) => {
                                        const v = e.target.value || null
                                        if (v) applyFilters({ asset_type: v, page: 1 })
                                        else applyFilters({ asset_type: null, page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All (use quick types above)</option>
                                    <option value="asset">Asset</option>
                                    <option value="deliverable">Execution</option>
                                    <option value="ai_generated">Generative</option>
                                    <option value="reference">Reference</option>
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
                                <label className="block text-xs font-medium text-slate-500 mb-1">Staged</label>
                                <select
                                    value={initialFilters?.intake_state ?? ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ intake_state: v === '' ? null : v, page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="staged">Staged only (uncategorized)</option>
                                    <option value="normal">Normal only (categorized)</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-500 mb-1">Builder staged</label>
                                <select
                                    value={initialFilters?.builder_staged === true ? '1' : initialFilters?.builder_staged === false ? '0' : ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ builder_staged: v === '' ? null : v === '1', page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="1">Builder staged only (detached)</option>
                                    <option value="0">Not builder staged</option>
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
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase w-[140px]" title="Library vs canvas export vs generative">
                                        Context
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Tenant</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Brand</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase" title="Builder-staged (detached) assets">Builder</th>
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
                                        <td colSpan={12} className="px-4 py-12 text-center text-slate-500">
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
                                                {a.admin_thumbnail_url ? (
                                                    <img src={a.admin_thumbnail_url} alt="" className="h-10 w-10 object-cover rounded" />
                                                ) : (
                                                    <div className="h-10 w-10 rounded bg-slate-200 flex items-center justify-center">
                                                        <PhotoIcon className="h-5 w-5 text-slate-400" />
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-900 max-w-[220px]" title={a.original_filename}>
                                                <div className="truncate font-medium text-slate-900">
                                                    {a.original_filename || a.title || '—'}
                                                </div>
                                                {formatFileSize(a.size_bytes) && (
                                                    <div className="text-xs text-slate-500 mt-0.5">{formatFileSize(a.size_bytes)}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 align-top">
                                                {a.row_context ? (
                                                    <div className="flex flex-col gap-0.5">
                                                        <span
                                                            className={`inline-flex w-fit rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${ROW_CONTEXT_BADGES[a.row_context.kind] || ROW_CONTEXT_BADGES.library}`}
                                                        >
                                                            {a.row_context.label}
                                                        </span>
                                                        {a.row_context.composition_id && (
                                                            <span className="font-mono text-[10px] text-slate-500" title="Composition id in metadata">
                                                                comp #{a.row_context.composition_id}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-600">{a.tenant?.name ?? '—'}</td>
                                            <td className="px-4 py-2 text-sm text-slate-600">{a.brand?.name ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                {a.builder_staged ? (
                                                    <span className="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800" title={a.builder_context || 'Builder staged'}>
                                                        {a.builder_context || 'Staged'}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
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
