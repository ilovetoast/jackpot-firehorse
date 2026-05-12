import { router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import { useState, useCallback, useRef, useEffect, useMemo } from 'react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminReliabilitySectionSidebar from '../../../Components/Admin/AdminReliabilitySectionSidebar'
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
    PlayCircleIcon,
} from '@heroicons/react/24/outline'
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

/** Drop query keys that match server defaults so URLs stay short (composition scope, first page, default sort). */
function shouldOmitFromQuery(key, value) {
    if (value === null || value === undefined || value === '') return true
    if (key === 'composition' || key === 'composition_only' || key === 'composition_layers_only') {
        return value === '0' || value === 0 || value === false || value === 'false'
    }
    if (key === 'include_composition') {
        return value === false || value === 'false'
    }
    if (key === 'reference_materials') {
        return value === false || value === 'false'
    }
    if (key === 'generative_workspace') {
        return value === false || value === 'false' || value == null
    }
    if (key === 'thumbnail_preview_issue') {
        return value !== true && value !== 'true' && value !== 1 && value !== '1'
    }
    if (key === 'page') {
        return value === 1 || value === '1'
    }
    if (key === 'sort') {
        return value === 'created_at'
    }
    if (key === 'sort_direction') {
        return value === 'desc'
    }
    return false
}

function buildQueryParams(filters, overrides = {}) {
    const q = { ...filters, ...overrides }
    // Laravel reads `composition`, not `include_composition` — never put include_composition in the URL.
    if (Object.prototype.hasOwnProperty.call(q, 'include_composition')) {
        if (q.include_composition === true) {
            q.composition = '1'
        }
        delete q.include_composition
    }

    const out = {}
    for (const [k, v] of Object.entries(q)) {
        if (shouldOmitFromQuery(k, v)) continue
        if (k === 'types' && Array.isArray(v)) {
            out[k] = v.join(',')
            continue
        }
        out[k] = v
    }
    return out
}

/** Short id for grid: last four characters, full id in title tooltip */
function formatIdPreview(id) {
    if (id == null || id === '') return '—'
    const s = String(id)
    if (s.length <= 4) return s
    return `…${s.slice(-4)}`
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
    if (!f || f.types === 'all' || f.generative_workspace === true) return false
    const t = f.types
    if (!Array.isArray(t) || t.length !== 2) return false
    const sorted = [...t].map(String).sort()
    return (
        sorted[0] === 'asset'
        && sorted[1] === 'deliverable'
        && f.include_composition === false
        && f.composition_only !== true
        && f.composition_layers_only !== true
    )
}

/** Toggle state for Asset / Execution / All (DB types). Queue filters clear the type row. */
function deriveAssetExecutionToggles(f) {
    if (!f) return { asset: true, execution: true, allTypesActive: false, ambiguous: false, generativeWorkspace: false }
    if (f.reference_materials === true || f.intake_state === 'staged') {
        return { asset: false, execution: false, allTypesActive: false, ambiguous: true, generativeWorkspace: false }
    }
    if (f.generative_workspace === true) {
        return { asset: false, execution: false, allTypesActive: false, ambiguous: false, generativeWorkspace: true }
    }
    // URL ?types=all → server may send types: 'all' or (legacy) null; both mean no DB type restriction — not asset+deliverable only.
    if (f.types === 'all' || (f.types == null && !f.asset_type)) {
        return { asset: false, execution: false, allTypesActive: true, ambiguous: false, generativeWorkspace: false }
    }
    if (f.asset_type === 'asset') return { asset: true, execution: false, allTypesActive: false, ambiguous: false, generativeWorkspace: false }
    if (f.asset_type === 'deliverable') return { asset: false, execution: true, allTypesActive: false, ambiguous: false, generativeWorkspace: false }
    if (f.asset_type === 'ai_generated') return { asset: false, execution: false, allTypesActive: false, ambiguous: true, generativeWorkspace: false }
    const types = Array.isArray(f.types) ? f.types.map(String) : []
    const hasAsset = types.includes('asset')
    const hasDel = types.includes('deliverable')
    const hasOther = types.some((t) => !['asset', 'deliverable'].includes(t))
    if (hasOther) return { asset: hasAsset, execution: hasDel, allTypesActive: false, ambiguous: true, generativeWorkspace: false }
    if (Array.isArray(f.types) && types.length === 0 && !f.asset_type) {
        return { asset: true, execution: true, allTypesActive: false, ambiguous: false, generativeWorkspace: false }
    }
    return { asset: hasAsset, execution: hasDel, allTypesActive: false, ambiguous: false, generativeWorkspace: false }
}

/**
 * Human-readable lines for “why no rows?” — driven by server-parsed filters (search box tokens are parsed into these).
 */
function summarizeActiveAdminFilters(f) {
    if (!f) return []
    const lines = []
    if (f.asset_type === 'deliverable') {
        lines.push('Type is limited to executions / deliverables (type:execution in search). Plain library files use DB type “asset” — they are excluded here.')
    } else if (f.asset_type === 'asset') {
        lines.push('Type is limited to file assets only (type:asset).')
    } else if (f.asset_type === 'ai_generated') {
        lines.push('Type is limited to AI generative assets.')
    } else if (f.types === 'all') {
        lines.push('No DB type restriction (all types: asset, execution, generative, reference).')
    } else if (Array.isArray(f.types) && f.types.length > 0) {
        lines.push(`DB types included: ${f.types.join(', ')}`)
    }
    if (f.composition_layers_only) {
        lines.push('Only composition-linked layers (metadata composition_id, not canvas export rows).')
    } else if (f.composition_only) {
        lines.push('Only composition-tagged (canvas WIP/preview) rows.')
    } else if (f.include_composition === false) {
        lines.push('Canvas export rows (WIP/preview) are hidden (default).')
    } else if (f.include_composition === true) {
        lines.push('Canvas export rows are included with other rows.')
    }
    if (f.generative_workspace === true) {
        lines.push(
            'Generative workspace: AI-generated assets and canvas export (WIP/preview) rows. Composition / Comp ref columns group editor assets; stale = only in version history; orphaned = unreferenced (cleanup).',
        )
    }
    if (f.reference_materials === true) {
        lines.push('Reference materials view: DB type reference and/or legacy Brand Builder staged rows.')
    }
    if (f.intake_state === 'staged') {
        lines.push('Intake staged only (awaiting category — member /assets/staged queue).')
    }
    if (f.thumbnail_preview_issue === true || f.thumbnail_preview_issue === 'true' || f.thumbnail_preview_issue === 1 || f.thumbnail_preview_issue === '1') {
        lines.push(
            'Preview issues: non-audio types from the file registry that expect thumbnails — failed, stuck processing, pending after analysis complete, or skipped with operational / stack skip reasons.',
        )
    }
    if (f.builder_staged === true) {
        lines.push('Builder-staged only (builder:true).')
    } else if (f.builder_staged === false) {
        lines.push('Builder-staged assets excluded (builder:false).')
    }
    if (f.tag) {
        lines.push(`Must have tag “${f.tag}”.`)
    }
    if (f.tenant_id) {
        lines.push(`Tenant id ${f.tenant_id}.`)
    }
    if (f.brand_id) {
        lines.push(`Brand id ${f.brand_id}.`)
    }
    if (f.brand_slug) {
        lines.push(`Brand name matches “${f.brand_slug}” (from brand:… in search).`)
    }
    if (f.composition_ref_state) {
        lines.push(`Composition ref state: ${f.composition_ref_state} (editor-linked generative / preview rows).`)
    }
    if (f.deleted === true || f.deleted === '1') {
        lines.push('Soft-deleted assets only.')
    } else if (f.deleted === false || f.deleted === '0') {
        lines.push('Active assets only (soft-deleted hidden).')
    }
    if (f.search && String(f.search).trim()) {
        lines.push(`Free-text filter: “${String(f.search).trim()}”.`)
    }
    return lines
}

function formatDateTime(isoString) {
    if (!isoString) return '—'
    const d = new Date(isoString)
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
}

function SortableTh({ label, sortKey, currentSort, sortDirection, onSort, className = '', title: thTitle }) {
    const isActive = currentSort === sortKey
    const handleClick = () => {
        const nextDir = isActive && sortDirection === 'desc' ? 'asc' : 'desc'
        onSort(sortKey, nextDir)
    }
    return (
        <th title={thTitle} className={`px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase cursor-pointer hover:bg-slate-100 select-none ${className}`} onClick={handleClick}>
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

    const emptyStateHints = useMemo(() => summarizeActiveAdminFilters(initialFilters), [initialFilters])

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

    // Keep the search box aligned with the server after Inertia navigations (stale tokens were confusing facets).
    useEffect(() => {
        setSearchInput(initialFilters?.search ?? '')
    }, [initialFilters?.search])

    const applyFilters = useCallback(
        (overrides = {}) => {
            const hasExplicitSearchOverride = Object.prototype.hasOwnProperty.call(overrides, 'search')
            const effectiveSearch = hasExplicitSearchOverride ? (overrides.search ?? '') : searchInput

            const prevParsed = parseSmartFilter(initialFilters?.search ?? '').filters
            const { plainSearch, filters: parsed } = parseSmartFilter(effectiveSearch)

            const base = { ...initialFilters }
            for (const k of Object.keys(prevParsed)) {
                if (!(k in parsed)) {
                    delete base[k]
                }
            }
            Object.assign(base, parsed)
            base.search = plainSearch

            const merged = { ...base, ...overrides }
            if (Object.prototype.hasOwnProperty.call(overrides, 'asset_type') && overrides.asset_type === null) {
                delete merged.asset_type
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
                merged.generative_workspace = false
            }
            const params = buildQueryParams(merged, {})
            router.get('/app/admin/assets', params, { preserveState: true, preserveScroll: true })
        },
        [searchInput, initialFilters],
    )

    const typeToggles = useMemo(() => deriveAssetExecutionToggles(initialFilters), [initialFilters])

    const toggleAssetExecutionType = useCallback(
        (key) => {
            let nextAsset = key === 'asset' ? !typeToggles.asset : typeToggles.asset
            let nextExecution = key === 'execution' ? !typeToggles.execution : typeToggles.execution
            if (!nextAsset && !nextExecution) {
                nextAsset = true
                nextExecution = true
            }
            const parts = []
            if (nextAsset) parts.push('asset')
            if (nextExecution) parts.push('deliverable')
            applyFilters({
                asset_type: null,
                types: parts,
                reference_materials: false,
                intake_state: null,
                generative_workspace: false,
                composition_only: false,
                composition_layers_only: false,
                page: 1,
            })
        },
        [applyFilters, typeToggles],
    )

    const applyAllDbTypes = useCallback(() => {
        applyFilters({
            asset_type: null,
            types: 'all',
            reference_materials: false,
            intake_state: null,
            generative_workspace: false,
            composition_only: false,
            composition_layers_only: false,
            page: 1,
        })
    }, [applyFilters])

    const applyGenerativeWorkspace = useCallback(() => {
        applyFilters({
            asset_type: null,
            types: null,
            reference_materials: false,
            intake_state: null,
            generative_workspace: true,
            composition_only: false,
            composition_layers_only: false,
            page: 1,
        })
    }, [applyFilters])

    const queueToggles = useMemo(
        () => ({
            stagedIntake: initialFilters?.intake_state === 'staged',
            referenceMaterials: initialFilters?.reference_materials === true,
        }),
        [initialFilters],
    )

    const previewIssueOn = useMemo(
        () =>
            initialFilters?.thumbnail_preview_issue === true
            || initialFilters?.thumbnail_preview_issue === 'true'
            || initialFilters?.thumbnail_preview_issue === 1
            || initialFilters?.thumbnail_preview_issue === '1',
        [initialFilters?.thumbnail_preview_issue],
    )

    const toggleStagedIntake = useCallback(() => {
        if (queueToggles.stagedIntake) {
            applyFilters({
                intake_state: null,
                types: ['asset', 'deliverable'],
                generative_workspace: false,
                page: 1,
            })
        } else {
            applyFilters({
                intake_state: 'staged',
                reference_materials: false,
                generative_workspace: false,
                asset_type: null,
                types: null,
                page: 1,
            })
        }
    }, [applyFilters, queueToggles.stagedIntake])

    const toggleReferenceMaterials = useCallback(() => {
        if (queueToggles.referenceMaterials) {
            applyFilters({
                reference_materials: false,
                generative_workspace: false,
                asset_type: null,
                types: ['asset', 'deliverable'],
                page: 1,
            })
        } else {
            applyFilters({
                reference_materials: true,
                intake_state: null,
                generative_workspace: false,
                asset_type: null,
                types: null,
                page: 1,
            })
        }
    }, [applyFilters, queueToggles.referenceMaterials])

    const togglePreviewIssue = useCallback(() => {
        if (previewIssueOn) {
            applyFilters({ thumbnail_preview_issue: false, page: 1 })
        } else {
            applyFilters({ thumbnail_preview_issue: true, page: 1 })
        }
    }, [applyFilters, previewIssueOn])

    const handleSearchSubmit = (e) => {
        e?.preventDefault()
        applyFilters({ page: 1 })
    }

    const handleSort = (sortKey, sortDirection) => {
        applyFilters({ sort: sortKey, sort_direction: sortDirection, page: 1 })
    }

    const clearSearchShowAllTypes = useCallback(() => {
        setSearchInput('')
        applyFilters({
            search: '',
            asset_type: null,
            types: ['asset', 'deliverable'],
            reference_materials: false,
            intake_state: null,
            generative_workspace: false,
            composition_only: false,
            composition_layers_only: false,
            page: 1,
        })
    }, [applyFilters])

    const hasActiveFilters = !!(
        initialFilters?.asset_id ||
        initialFilters?.search ||
        initialFilters?.tenant_id ||
        initialFilters?.brand_id ||
        initialFilters?.status ||
        initialFilters?.asset_type ||
        initialFilters?.analysis_status ||
        initialFilters?.thumbnail_status ||
        initialFilters?.thumbnail_preview_issue === true ||
        initialFilters?.thumbnail_preview_issue === 'true' ||
        initialFilters?.thumbnail_preview_issue === 1 ||
        initialFilters?.thumbnail_preview_issue === '1' ||
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
        || initialFilters?.composition_layers_only === true
        || initialFilters?.reference_materials === true
        || initialFilters?.intake_state === 'staged'
        || initialFilters?.generative_workspace === true
        || !!initialFilters?.composition_ref_state
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
            } else if (action === 'publish') {
                await axios.post(`/app/admin/assets/${assetId}/publish`)
            } else if (action === 'unpublish') {
                await axios.post(`/app/admin/assets/${assetId}/unpublish`)
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
        { id: 'generate_video_previews', label: 'Regenerate hover video previews', icon: PlayCircleIcon },
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
        <div className="min-h-full">
            <AppHead title="Asset Operations" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="reliability"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Reliability Center', href: '/app/admin/reliability' },
                        { label: 'Asset Operations' },
                    ]}
                    title="Asset Operations"
                    description="Cross-tenant search, repair, restore"
                    sidebar={<AdminReliabilitySectionSidebar />}
                >
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
                                placeholder="Filename, title, or tags — optional tenant:1 brand:slug tag:…"
                                className="relative z-10 block w-full rounded-lg border-slate-300 pl-10 pr-4 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Search
                        </button>
                        <button
                            type="button"
                            onClick={clearSearchShowAllTypes}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            title="Remove search tokens (tenant, brand, tag, builder, type:…) and show all assets and executions"
                        >
                            Clear search
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

                {/* Type + Queue filters (single row) */}
                <div className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-2 shadow-sm">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Type</span>
                        <div
                            className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner"
                            role="group"
                            aria-label="Library asset types"
                        >
                            <button
                                type="button"
                                aria-pressed={typeToggles.asset && !typeToggles.allTypesActive}
                                onClick={() => toggleAssetExecutionType('asset')}
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    typeToggles.asset && !typeToggles.allTypesActive
                                        ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Asset
                            </button>
                            <button
                                type="button"
                                aria-pressed={typeToggles.execution && !typeToggles.allTypesActive}
                                onClick={() => toggleAssetExecutionType('execution')}
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    typeToggles.execution && !typeToggles.allTypesActive
                                        ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Execution
                            </button>
                            <button
                                type="button"
                                aria-pressed={typeToggles.generativeWorkspace === true}
                                onClick={applyGenerativeWorkspace}
                                title="AI-generated assets (DB type) and canvas export rows (WIP/preview metadata)"
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-2 ${
                                    typeToggles.generativeWorkspace
                                        ? 'bg-sky-100 text-sky-900 shadow-sm ring-1 ring-sky-300/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Generative
                            </button>
                            <span className="mx-0.5 h-5 w-px shrink-0 bg-slate-300/90" aria-hidden />
                            <button
                                type="button"
                                aria-pressed={typeToggles.allTypesActive}
                                onClick={applyAllDbTypes}
                                title="Show every DB type (asset, execution, generative, reference)"
                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    typeToggles.allTypesActive
                                        ? 'bg-indigo-600 text-white shadow-sm ring-1 ring-indigo-700/20'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-900'
                                }`}
                            >
                                All
                            </button>
                        </div>

                        <span className="hidden sm:block h-5 w-px shrink-0 bg-slate-300/90" aria-hidden />

                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Queue</span>
                        <div
                            className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner"
                            role="radiogroup"
                            aria-label="Queue: staged intake or reference materials (one at a time)"
                        >
                            <button
                                type="button"
                                role="radio"
                                aria-checked={queueToggles.stagedIntake}
                                onClick={toggleStagedIntake}
                                title="Assets with intake_state=staged (awaiting category — same idea as /assets/staged)"
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    queueToggles.stagedIntake
                                        ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Staged intake
                            </button>
                            <button
                                type="button"
                                role="radio"
                                aria-checked={queueToggles.referenceMaterials}
                                onClick={toggleReferenceMaterials}
                                title="Reference materials: type=reference OR legacy builder_staged (Brand Builder uploads)"
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    queueToggles.referenceMaterials
                                        ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Reference materials
                            </button>
                        </div>

                        <span className="hidden sm:block h-5 w-px shrink-0 bg-slate-300/90" aria-hidden />

                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Preview</span>
                        <div
                            className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-100/90 p-1 shadow-inner"
                            role="group"
                            aria-label="Thumbnail preview health"
                        >
                            <button
                                type="button"
                                aria-pressed={previewIssueOn}
                                onClick={togglePreviewIssue}
                                title="Registry types with thumbnail previews (excludes audio e.g. MP3): failed, pending after complete analysis, stalled processing, or skipped with operational / unsupported_format reasons"
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                                    previewIssueOn
                                        ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-indigo-200/90'
                                        : 'text-slate-600 hover:bg-white/70 hover:text-slate-800'
                                }`}
                            >
                                Preview issues
                            </button>
                        </div>
                    </div>
                    {typeToggles.ambiguous && (
                        <p className="mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-2 py-1.5">
                            {queueToggles.referenceMaterials ? (
                                <>
                                    <strong className="text-amber-900">Reference materials</strong> is on — type pills are off until you switch back or turn off Reference in Queue.
                                </>
                            ) : queueToggles.stagedIntake ? (
                                <>
                                    <strong className="text-amber-900">Staged intake</strong> is on — type pills are off until you turn off Staged or pick a type above.
                                </>
                            ) : (
                                <>
                                    Other DB types or filters are active (e.g. advanced asset type, or <code className="text-[11px]">type:</code> in search).
                                </>
                            )}
                        </p>
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
                                    onChange={(e) => {
                                        const v = e.target.value || null
                                        if (v) applyFilters({ asset_type: v, generative_workspace: false, page: 1 })
                                        else applyFilters({ asset_type: null, generative_workspace: false, page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All (use type toggles above)</option>
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
                                <label className="block text-xs font-medium text-slate-500 mb-1" title="Editor-linked generative / canvas rows (metadata)">
                                    Comp ref
                                </label>
                                <select
                                    value={initialFilters?.composition_ref_state ?? ''}
                                    onChange={(e) => applyFilters({ composition_ref_state: e.target.value || null, page: 1 })}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option value="active">Active</option>
                                    <option value="stale">Stale</option>
                                    <option value="orphaned">Orphaned</option>
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
                                <label className="block text-xs font-medium text-slate-500 mb-1" title="All lists both active and soft-deleted assets (same total as active + deleted-only)">
                                    Deleted
                                </label>
                                <select
                                    value={initialFilters?.deleted === true ? '1' : initialFilters?.deleted === false ? '0' : ''}
                                    onChange={(e) => {
                                        const v = e.target.value
                                        applyFilters({ deleted: v === '' ? null : v === '1', page: 1 })
                                    }}
                                    className="block w-full rounded border-slate-300 text-sm"
                                >
                                    <option value="">All (active + deleted)</option>
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
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase w-[4.5rem]" title="Last four characters; full id on hover">
                                        ID
                                    </th>
                                    <th className="w-16 px-2 py-3 text-left text-xs font-medium text-slate-500 uppercase" title="Grid preview image">
                                        Preview
                                    </th>
                                    <SortableTh
                                        label="Filename"
                                        sortKey="filename"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                    />
                                    <SortableTh
                                        label="Size"
                                        sortKey="size"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                        title="File size (bytes on asset row)"
                                    />
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase w-[140px]" title="Library vs canvas / generative; soft-deleted assets show a Deleted badge first">
                                        Context
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase max-w-[9rem]" title="Tenant and brand (narrow with filters)">
                                        Tenant / brand
                                    </th>
                                    <SortableTh
                                        label="Analysis"
                                        sortKey="analysis_status"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                        title="Pipeline stage for the asset (upload → extract → thumbnails → embedding → complete)"
                                    />
                                    <SortableTh
                                        label="Thumb status"
                                        sortKey="thumbnail_status"
                                        currentSort={initialFilters?.sort ?? 'created_at'}
                                        sortDirection={initialFilters?.sort_direction ?? 'desc'}
                                        onSort={handleSort}
                                        title="Thumbnail generation pipeline status (Preview column shows the image)"
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
                                        <td colSpan={11} className="px-4 py-12 text-center text-slate-600">
                                            <p className="text-base font-medium text-slate-800">No assets match this query</p>
                                            {emptyStateHints.length > 0 && (
                                                <div className="mt-4 max-w-2xl mx-auto text-left">
                                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">
                                                        Active constraints (search bar tokens narrow results; <code className="text-[10px]">type:…</code> is overridden by Asset / Execution checkboxes)
                                                    </p>
                                                    <ul className="text-sm list-disc pl-5 space-y-1.5 text-slate-600">
                                                        {emptyStateHints.map((line, i) => (
                                                            <li key={i}>{line}</li>
                                                        ))}
                                                    </ul>
                                                    <p className="mt-4 text-sm text-slate-500">
                                                        Example: a Photography file like <span className="font-mono text-slate-700">green.jpg</span> is usually DB type{' '}
                                                        <strong className="text-slate-700">asset</strong>. It will not appear if the search still contains{' '}
                                                        <span className="font-mono bg-slate-100 px-1 rounded">type:execution</span> (deliverables only), or a different{' '}
                                                        <span className="font-mono bg-slate-100 px-1 rounded">brand:…</span> than that file’s brand.
                                                    </p>
                                                </div>
                                            )}
                                            {emptyStateHints.length === 0 && (
                                                <p className="mt-2 text-sm text-slate-500">Try clearing the search box or use Clear all.</p>
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    assets.map((a) => (
                                        <tr
                                            key={a.id}
                                            className={`hover:bg-slate-50 cursor-pointer ${a.deleted_at ? 'bg-slate-50/90' : ''}`}
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
                                            <td className="px-4 py-2 max-w-[5rem]">
                                                <span className="font-mono text-xs text-slate-600 block truncate" title={a.id}>
                                                    {formatIdPreview(a.id)}
                                                </span>
                                            </td>
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
                                            </td>
                                            <td className="px-4 py-2 text-sm text-slate-600 whitespace-nowrap tabular-nums" title={a.size_bytes != null ? `${a.size_bytes} bytes` : undefined}>
                                                {formatFileSize(a.size_bytes) || '—'}
                                            </td>
                                            <td className="px-4 py-2 align-top">
                                                <div className="flex flex-col gap-0.5">
                                                    {a.deleted_at && (
                                                        <span
                                                            className="inline-flex w-fit rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-rose-100 text-rose-900 ring-1 ring-rose-200/80"
                                                            title={a.deleted_at ? `Soft-deleted ${formatDateTime(a.deleted_at)}` : undefined}
                                                        >
                                                            Deleted
                                                        </span>
                                                    )}
                                                    {a.row_context ? (
                                                        <>
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
                                                        </>
                                                    ) : (
                                                        !a.deleted_at && <span className="text-slate-400">—</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 max-w-[9rem]" title={`${a.tenant?.name ?? '—'} · ${a.brand?.name ?? '—'}`}>
                                                <div className="truncate text-sm text-slate-800">{a.tenant?.name ?? '—'}</div>
                                                <div className="truncate text-xs text-slate-500">{a.brand?.name ?? '—'}</div>
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
                </AdminShell>
            </main>

            {/* Asset Detail Modal */}
            {(detailAsset !== null || detailLoading) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={closeDetail}>
                    <div
                        className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
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
                                onDetailDataReplace={(d) => setDetailAsset(d)}
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
