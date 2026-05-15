/**
 * Asset Grid Secondary Filters Component
 * 
 * Secondary filter UI with inline expansion behavior.
 * Renders only visible secondary filters (metadata fields).
 * 
 * This component uses existing filter helpers:
 * - normalizeFilterConfig: Normalizes Inertia props
 * - filterTierResolver.getSecondaryFilters(): Gets secondary filters from schema
 * - filterVisibilityRules.getVisibleFilters(): Filters to visible only
 * 
 * ⚠️ CONSTRAINTS:
 * - React component only (render-only)
 * - No helper modifications
 * - No resolver changes
 * - No new filtering logic
 * 
 * FILTER ROUTING:
 * - PRIMARY filters: is_primary === true → rendered by AssetGridMetadataPrimaryFilters
 * - SECONDARY filters: is_primary !== true (false, null, undefined) → rendered here
 * - Defensive default: Missing is_primary treated as secondary (backward compatibility)
 * 
 * @module AssetGridSecondaryFilters
 */

import { useState, useEffect, useMemo, useCallback } from 'react'
import { usePage, router, Link } from '@inertiajs/react'
import {
    XMarkIcon,
    PlusIcon,
    ClockIcon,
    ArchiveBoxIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    TagIcon,
    CubeTransparentIcon,
    SparklesIcon,
    Squares2X2Icon,
    AdjustmentsHorizontalIcon,
    SwatchIcon,
    RectangleStackIcon,
    EllipsisHorizontalCircleIcon,
} from '@heroicons/react/24/outline'
import SortDropdown from './SortDropdown'
import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
import { getSecondaryFilters, getPrimaryFilters } from '../utils/filterTierResolver'
import { getVisibleFilters, getHiddenFilters, getHiddenFilterCount, getFilterVisibilityState } from '../utils/filterVisibilityRules'
import { isFilterCompatible, resolveVisibilityAssetType } from '../utils/filterScopeRules'
import {
    parseFiltersFromUrl,
    buildUrlParamsWithFlatFilters,
    normalizeFilterParam,
    clearToolbarFilterParams,
    toolbarQueryHasClearableFilters,
} from '../utils/filterUrlUtils'
import { partitionFilterLayoutFields, getFieldKey } from '../utils/filterFieldGrouping'
import { FilterFieldInput } from './FilterFieldInput'
import { resolve, CONTEXT, WIDGET } from '../utils/widgetResolver'
import { usePermission } from '../hooks/usePermission'
import UserSelect from './UserSelect'
import Avatar from './Avatar'
import MoreFiltersTriggerButton from './MoreFiltersTriggerButton'
import { getWorkspaceButtonColor, hexToRgba } from '../utils/colorUtils'

const GRID_PARTIAL_RELOAD_KEYS = [
    'assets',
    'next_page_url',
    'filters',
    'filterable_schema',
    'available_values',
    'uploaded_by_users',
    'filtered_grid_total',
    'grid_folder_total',
]

function findUploadedByUser(users, rawId) {
    if (rawId == null || rawId === '' || !Array.isArray(users)) {
        return null
    }
    const sid = String(rawId)
    return users.find((u) => String(u.id) === sid) ?? null
}

const FILTER_GRID_CLASS =
    'grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]'

function FilterCollapsibleSection({ title, icon: Icon, open, onToggle, children }) {
    return (
        <section className="rounded-xl border border-gray-200/60 bg-white/80 shadow-sm overflow-visible">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-semibold text-gray-900 hover:bg-gray-50/80"
            >
                <span className="flex items-center gap-2 min-w-0">
                    {Icon ? <Icon className="h-5 w-5 shrink-0 text-gray-500" aria-hidden /> : null}
                    <span className="truncate">{title}</span>
                </span>
                {open ? (
                    <ChevronDownIcon className="h-5 w-5 shrink-0 text-gray-400" aria-hidden />
                ) : (
                    <ChevronRightIcon className="h-5 w-5 shrink-0 text-gray-400" aria-hidden />
                )}
            </button>
            {open ? <div className="border-t border-gray-200/60 px-4 py-4">{children}</div> : null}
        </section>
    )
}

/**
 * Secondary Filter Bar Component
 * 
 * Renders secondary filters (metadata fields) in an expandable container.
 * Only visible secondary filters are shown.
 * 
 * @param {Object} props
 * @param {Array} props.filterable_schema - Filterable metadata schema from backend
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Object} props.available_values - Map of field_key to available values
 * @param {boolean} props.canManageFields - Whether user has permission to manage metadata fields
 * @param {string} props.assetType - Current asset type (defaults to 'asset')
 * @param {string} [props.sortBy] - Current sort field (featured | created | quality | modified | alphabetical)
 * @param {string} [props.sortDirection] - asc | desc
 * @param {Function} [props.onSortChange] - (sortBy, sortDirection) => void
 * @param {number} [props.assetResultCount] - Number of assets currently visible (e.g. revealed by infinite scroll)
 * @param {number} [props.totalInCategory] - Legacy fallback total when server total is absent
 * @param {number|null} [props.filteredGridTotal] - Server paginator total for current query (filtered count, numerator when present)
 * @param {number|null} [props.gridFolderTotal] - Total in current library scope (category/All + q), excluding metadata filters; denominator for "x of y"
 * @param {boolean} [props.hasMoreAvailable] - If true, show "x of y+" when more can be loaded (only without server total)
 * @param {React.ReactNode} [props.barTrailingContent] - Optional content on the right of the bar (same line as count and Sort), e.g. Select Multiple / Select all
 * @param {boolean} [props.showComplianceFilter] - If true, show Brand DNA compliance filter (Deliverables only)
 * @param {string} [props.complianceFilter] - Current compliance filter value
 * @param {Function} [props.onComplianceFilterChange] - (value) => void
 * @param {boolean} [props.toolbarMoreFiltersExpanded] - When set with onToolbarMoreFiltersExpandedChange + hideInlineMoreFiltersButton, expansion is controlled from AssetGridToolbar.
 * @param {Function} [props.onToolbarMoreFiltersExpandedChange]
 * @param {boolean} [props.hideInlineMoreFiltersButton] - Hide funnel in this bar (button lives in toolbar).
 * @param {Function} [props.onToolbarMoreFiltersMetaReport] - Reports counts for toolbar badge / hints.
 * @param {boolean} [props.hideSortInSecondaryBar] - When true, Sort lives in AssetGridToolbar (next to More filters).
 * @param {string[]} [props.filterUrlNavigationKeys] - Query keys that are not metadata filters (e.g. Collections `collection`, `category_id`).
 * @param {string[]|null} [props.inertiaPartialReloadKeys] - Inertia `only` keys for filter navigations; default asset grid keys when null.
 * @param {boolean} [props.clearFiltersCollectionsView] - Pass true on Collections so clear-all matches toolbar behavior.
 */
export default function AssetGridSecondaryFilters({
    filterable_schema = [],
    selectedCategoryId = null,
    available_values = {},
    canManageFields = false,
    assetType = 'asset',
    primaryColor,
    sortBy = 'created',
    sortDirection = 'desc',
    onSortChange = null,
    assetResultCount = null,
    totalInCategory = null,
    filteredGridTotal = null,
    gridFolderTotal = null,
    hasMoreAvailable = false,
    barTrailingContent = null,
    showComplianceFilter = false,
    complianceFilter = '',
    onComplianceFilterChange = null,
    toolbarMoreFiltersExpanded,
    onToolbarMoreFiltersExpandedChange,
    hideInlineMoreFiltersButton = false,
    onToolbarMoreFiltersMetaReport,
    hideSortInSecondaryBar = false,
    filterUrlNavigationKeys = [],
    inertiaPartialReloadKeys = null,
    clearFiltersCollectionsView = false,
}) {
    const page = usePage()
    const pageProps = page.props
    const { auth } = pageProps
    const gridFileTypeGroups = useMemo(() => {
        const g = pageProps?.dam_file_types?.grid_file_type_filter_options?.grouped
        return Array.isArray(g) ? g : []
    }, [pageProps?.dam_file_types?.grid_file_type_filter_options])
    const fileTypeFilterLabelByKey = useMemo(() => {
        const m = new Map()
        for (const grp of gridFileTypeGroups) {
            for (const t of grp.types || []) {
                if (t?.key) m.set(String(t.key), String(t.label || t.key))
            }
        }
        return m
    }, [gridFileTypeGroups])
    const brandPrimary =
        primaryColor ||
        getWorkspaceButtonColor(auth?.activeBrand) ||
        auth?.activeBrand?.primary_color ||
        '#6366f1'

    /** Ghost chip: brand tint fill + brand primary copy (never slate/black) — matches workspace filter badge contract. */
    const appliedMetadataPillStyle = useMemo(
        () => ({
            backgroundColor: hexToRgba(brandPrimary, 0.1),
            color: brandPrimary,
            border: `1px solid ${hexToRgba(brandPrimary, 0.22)}`,
        }),
        [brandPrimary],
    )

    const partialReloadKeys = useMemo(
        () =>
            Array.isArray(inertiaPartialReloadKeys) && inertiaPartialReloadKeys.length > 0
                ? inertiaPartialReloadKeys
                : GRID_PARTIAL_RELOAD_KEYS,
        [inertiaPartialReloadKeys]
    )

    const filterUrlNavOptions = useMemo(
        () => ({ navigationKeys: filterUrlNavigationKeys || [] }),
        [filterUrlNavigationKeys]
    )
    
    // Phase L.5.1: Check permissions for lifecycle filters
    // Pending Publication (pending_publication) requires asset.publish
    // Unpublished requires metadata.bypass_approval
    // Archived requires asset.archive
    const { can } = usePermission()
    const canPublish = can('asset.publish')
    const canBypassApproval = can('metadata.bypass_approval')
    const canArchive = can('asset.archive')
    
    // Phase L.5.1: Lifecycle filter state (all three filters)
    const [pendingPublicationFilter, setPendingPublicationFilter] = useState(false)
    const [unpublishedFilter, setUnpublishedFilter] = useState(false)
    const [archivedFilter, setArchivedFilter] = useState(false)
    
    // User filter state
    const [userFilter, setUserFilter] = useState(null)
    
    // File type filter state
    const [fileTypeFilter, setFileTypeFilter] = useState('all')
    
    // Check URL for lifecycle filters, user filter, and file type filter on mount
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search)
        const lifecycle = urlParams.get('lifecycle')
        setPendingPublicationFilter(lifecycle === 'pending_publication')
        setUnpublishedFilter(lifecycle === 'unpublished')
        setArchivedFilter(lifecycle === 'archived')
        
        // Check for user filter
        const uploadedBy = urlParams.get('uploaded_by')
        setUserFilter(uploadedBy || null)
        
        // Check for file type filter
        const fileType = urlParams.get('file_type')
        setFileTypeFilter(fileType || 'all')
    }, [])
    
    // Sync with URL changes
    useEffect(() => {
        const handleUrlChange = () => {
            const urlParams = new URLSearchParams(window.location.search)
            const lifecycle = urlParams.get('lifecycle')
            setPendingPublicationFilter(lifecycle === 'pending_publication')
            setUnpublishedFilter(lifecycle === 'unpublished')
            setArchivedFilter(lifecycle === 'archived')
            
            // Sync user filter
            const uploadedBy = urlParams.get('uploaded_by')
            setUserFilter(uploadedBy || null)
            
            // Sync file type filter
            const fileType = urlParams.get('file_type')
            setFileTypeFilter(fileType || 'all')
        }
        
        // Listen for URL changes
        window.addEventListener('popstate', handleUrlChange)
        // Also check periodically in case URL changes without popstate
        const interval = setInterval(() => {
            const urlParams = new URLSearchParams(window.location.search)
            const lifecycle = urlParams.get('lifecycle')
            const newPending = lifecycle === 'pending_publication'
            const newUnpublished = lifecycle === 'unpublished'
            const newArchived = lifecycle === 'archived'
            const newUploadedBy = urlParams.get('uploaded_by') || null
            const newFileType = urlParams.get('file_type') || 'all'
            
            if (newPending !== pendingPublicationFilter) {
                setPendingPublicationFilter(newPending)
            }
            if (newUnpublished !== unpublishedFilter) {
                setUnpublishedFilter(newUnpublished)
            }
            if (newArchived !== archivedFilter) {
                setArchivedFilter(newArchived)
            }
            if (newUploadedBy !== userFilter) {
                setUserFilter(newUploadedBy)
            }
            if (newFileType !== fileTypeFilter) {
                setFileTypeFilter(newFileType)
            }
        }, 200)
        
        return () => {
            window.removeEventListener('popstate', handleUrlChange)
            clearInterval(interval)
        }
    }, [pendingPublicationFilter, unpublishedFilter, archivedFilter, userFilter, fileTypeFilter])
    
    // Handle pending publication filter toggle
    const handlePendingPublicationFilterToggle = () => {
        const urlParams = new URLSearchParams(window.location.search)
        const newFilterState = !pendingPublicationFilter
        
        // Only one lifecycle filter can be active at a time
        if (newFilterState) {
            urlParams.set('lifecycle', 'pending_publication')
            setPendingPublicationFilter(true)
            setUnpublishedFilter(false)
            setArchivedFilter(false)
        } else {
            urlParams.delete('lifecycle')
            setPendingPublicationFilter(false)
        }
        
        // Update URL and reload assets
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    // Handle unpublished filter toggle
    const handleUnpublishedFilterToggle = () => {
        const urlParams = new URLSearchParams(window.location.search)
        const newFilterState = !unpublishedFilter
        
        // Only one lifecycle filter can be active at a time
        if (newFilterState) {
            urlParams.set('lifecycle', 'unpublished')
            setPendingPublicationFilter(false)
            setUnpublishedFilter(true)
            setArchivedFilter(false)
        } else {
            urlParams.delete('lifecycle')
            setUnpublishedFilter(false)
        }
        
        // Update URL and reload assets
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    // Handle archived filter toggle
    const handleArchivedFilterToggle = () => {
        const urlParams = new URLSearchParams(window.location.search)
        const newFilterState = !archivedFilter
        
        // Only one lifecycle filter can be active at a time
        if (newFilterState) {
            urlParams.set('lifecycle', 'archived')
            setPendingPublicationFilter(false)
            setUnpublishedFilter(false)
            setArchivedFilter(true)
        } else {
            urlParams.delete('lifecycle')
            setArchivedFilter(false)
        }
        
        // Update URL and reload assets
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    // Handle user filter change
    const handleUserFilterChange = (userId) => {
        const urlParams = new URLSearchParams(window.location.search)
        
        if (userId) {
            urlParams.set('uploaded_by', userId)
            setUserFilter(userId)
        } else {
            urlParams.delete('uploaded_by')
            setUserFilter(null)
        }
        
        // Update URL and reload assets
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    // Handle file type filter change
    const handleFileTypeFilterChange = (fileType) => {
        const urlParams = new URLSearchParams(window.location.search)
        
        if (fileType && fileType !== 'all') {
            urlParams.set('file_type', fileType)
            setFileTypeFilter(fileType)
        } else {
            urlParams.delete('file_type')
            setFileTypeFilter('all')
        }
        
        // Update URL and reload assets
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    // Normalize filter config using existing helper
    // This ensures consistent shape from potentially inconsistent Inertia props
    const normalizedConfig = useMemo(() => {
        const normalized = normalizeFilterConfig({
            auth: pageProps.auth,
            selected_category: selectedCategoryId,
            asset_type: assetType,
            filterable_schema: filterable_schema,
            available_values: available_values,
        })
        
        return normalized
    }, [pageProps.auth, selectedCategoryId, assetType, filterable_schema, available_values])
    
    // Get secondary filters using existing helper
    // Secondary filters = filterable metadata fields where effective_is_primary !== true
    // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
    // The tier resolver (getSecondaryFilters) already handles routing:
    // - effective_is_primary === true → primary tier (excluded from getSecondaryFilters)
    // - effective_is_primary !== true (false, null, undefined) → secondary tier (included in getSecondaryFilters)
    // effective_is_primary is computed by MetadataSchemaResolver from category override (category-scoped)
    // Primary metadata filters (effective_is_primary === true) are rendered by AssetGridMetadataPrimaryFilters component
    const secondaryFilters = useMemo(() => {
        // getSecondaryFilters already returns only secondary-tier filters
        // The tier resolver's classifyFilter function routes based on isPrimaryFilter check
        // which uses field.is_primary (already effective_is_primary from schema resolution)
        // No additional filtering needed - trust the tier resolver
        return getSecondaryFilters(normalizedConfig)
    }, [normalizedConfig])
    
    // Get visible secondary filters using existing helper
    // This filters out any secondary filters that shouldn't be shown
    // Note: getSecondaryFilters returns FilterClassification objects with .field property
    const visibilityContext = useMemo(() => {
        let urlFileType = null
        try {
            const q = page.url?.includes?.('?') ? page.url.split('?')[1] : (typeof window !== 'undefined' ? window.location.search.slice(1) : '')
            urlFileType = new URLSearchParams(q).get('file_type')
        } catch { /* ignore */ }
        return {
            category_id: normalizedConfig.category_id,
            asset_type: resolveVisibilityAssetType(urlFileType, normalizedConfig.asset_type),
            available_values: normalizedConfig.available_values,
        }
    }, [normalizedConfig, page.url])
    
    const visibleSecondaryFilters = useMemo(() => {
        const filterFields = secondaryFilters.map(classification => classification.field || classification)
        return filterFields.filter(field => isFilterCompatible(field, visibilityContext))
    }, [secondaryFilters, visibilityContext, filterable_schema, selectedCategoryId, available_values])
    
    // Primary metadata filters (is_primary === true) — shown in More filters panel on mobile only
    const primaryFilterClassifications = useMemo(() => getPrimaryFilters(normalizedConfig), [normalizedConfig])
    const visiblePrimaryFilters = useMemo(() => {
        const filterFields = primaryFilterClassifications.map(classification => classification.field || classification)
        const metadataFields = filterFields.filter(field => {
            const fieldKey = field.field_key || field.key
            return fieldKey !== 'search' && fieldKey !== 'category' && fieldKey !== 'asset_type' && fieldKey !== 'brand'
        })
        const primaryMetadataFields = metadataFields.filter(field => field.is_primary === true)
        return getVisibleFilters(primaryMetadataFields, visibilityContext)
    }, [primaryFilterClassifications, visibilityContext, filterable_schema, selectedCategoryId, available_values])

    const layoutBuckets = useMemo(
        () => partitionFilterLayoutFields(visiblePrimaryFilters, visibleSecondaryFilters),
        [visiblePrimaryFilters, visibleSecondaryFilters]
    )

    // Get hidden secondary filter count using existing helper
    // This is used for awareness messaging only (does not reveal hidden filters)
    const hiddenFilterCount = useMemo(() => {
        return getHiddenFilterCount(secondaryFilters, visibilityContext)
    }, [secondaryFilters, visibilityContext])
    
    const filterKeys = useMemo(
        () => (filterable_schema || []).map(f => f.field_key || f.key).filter(Boolean),
        [filterable_schema]
    )
    
    // Primary filter keys (is_primary === true) — their applied pills render here, inline with "More filters"
    const primaryFilterKeys = useMemo(
        () => new Set((filterable_schema || []).filter(f => f.is_primary === true).map(f => f.field_key || f.key).filter(Boolean)),
        [filterable_schema]
    )
    
    const getFieldLabel = (fieldKey) => {
        const field = (filterable_schema || []).find(f => (f.field_key || f.key) === fieldKey)
        return field?.display_label || field?.label || fieldKey
    }

    const serverFilters = page.props.filters

    /** Inertia often passes new object/array refs each render; use stable keys for sync effect deps. */
    const serverFiltersSyncKey = useMemo(() => JSON.stringify(serverFilters ?? null), [serverFilters])
    const filterKeysSyncKey = useMemo(() => filterKeys.join('\0'), [filterKeys])
    const filterUrlNavSyncKey = useMemo(
        () => JSON.stringify(filterUrlNavigationKeys || []),
        [filterUrlNavigationKeys]
    )

    const [internalExpanded, setInternalExpanded] = useState(false)
    const toolbarControlled =
        hideInlineMoreFiltersButton &&
        typeof toolbarMoreFiltersExpanded === 'boolean' &&
        typeof onToolbarMoreFiltersExpandedChange === 'function'
    const isExpanded = toolbarControlled ? toolbarMoreFiltersExpanded : internalExpanded
    const setIsExpanded = toolbarControlled ? onToolbarMoreFiltersExpandedChange : setInternalExpanded

    const [sectionBasicOpen, setSectionBasicOpen] = useState(true)
    const [sectionAssetOpen, setSectionAssetOpen] = useState(false)
    const [sectionAiOpen, setSectionAiOpen] = useState(false)
    const [sectionCustomOpen, setSectionCustomOpen] = useState(false)
    const [sectionOtherOpen, setSectionOtherOpen] = useState(false)
    const [customFieldsExpanded, setCustomFieldsExpanded] = useState(false)
    const [otherFieldsExpanded, setOtherFieldsExpanded] = useState(false)

    function normalizeIncomingFilters(raw) {
        const out = {}
        for (const [key, def] of Object.entries(raw || {})) {
            if (!def || (def.value === undefined && def.value === null)) continue
            const v = def.value
            if (['tags', 'collection', 'dominant_hue_group'].includes(key)) {
                out[key] = { operator: def.operator || 'equals', value: normalizeFilterParam(v) }
            } else {
                out[key] = { operator: def.operator || 'equals', value: v }
            }
        }
        return out
    }

    const [filters, setFilters] = useState(() => {
        try {
            if (serverFilters && typeof serverFilters === 'object' && Object.keys(serverFilters).length > 0) {
                return normalizeIncomingFilters(serverFilters)
            }
            const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : (page.url?.split('?')[1] || ''))
            return parseFiltersFromUrl(urlParams, filterKeys, filterUrlNavOptions)
        } catch (e) { /* ignore */ }
        return {}
    })
    
    // Sync from server `filters` or URL. Deps use *SyncKey values so Inertia’s new object refs each render don’t loop setFilters.
    useEffect(() => {
        let next
        if (serverFilters && typeof serverFilters === 'object' && Object.keys(serverFilters).length > 0) {
            next = normalizeIncomingFilters(serverFilters)
        } else {
            const search =
                typeof window !== 'undefined'
                    ? window.location.search
                    : page.url?.includes('?')
                      ? `?${(page.url || '').split('?')[1] || ''}`
                      : ''
            const urlParams = new URLSearchParams(search)
            next = parseFiltersFromUrl(urlParams, filterKeys, filterUrlNavOptions)
        }
        setFilters((prev) => (JSON.stringify(prev) === JSON.stringify(next) ? prev : next))
    }, [page.url, serverFiltersSyncKey, filterKeysSyncKey, filterUrlNavSyncKey])
    
    const handleFilterChange = (fieldKey, operator, value) => {
        const newFilters = { ...filters, [fieldKey]: { operator, value } }
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            delete newFilters[fieldKey]
        }
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys, {
            preserveQueryKeys: filterUrlNavigationKeys || [],
        })
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }
    
    const handleRemoveFilter = (fieldKey) => {
        const newFilters = { ...filters }
        delete newFilters[fieldKey]
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys, {
            preserveQueryKeys: filterUrlNavigationKeys || [],
        })
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }

    const handleClearAllFiltersExpanded = useCallback(() => {
        const searchPart = page.url.includes('?') ? `?${page.url.split('?')[1]}` : ''
        const next = clearToolbarFilterParams(searchPart, {
            filterKeys,
            collectionsView: clearFiltersCollectionsView,
        })
        router.get(window.location.pathname, Object.fromEntries(next), {
            preserveState: true,
            preserveScroll: true,
            only: partialReloadKeys,
        })
    }, [page.url, filterKeys, clearFiltersCollectionsView, partialReloadKeys])

    const hasClearableToolbarFilters = useMemo(() => {
        const searchPart = page.url.includes('?') ? `?${page.url.split('?')[1]}` : ''
        return toolbarQueryHasClearableFilters(searchPart, filterKeys, clearFiltersCollectionsView)
    }, [page.url, filterKeys, clearFiltersCollectionsView])

    // Count active filters (including lifecycle filters and user filter)
    const metadataFilterCount = Object.values(filters).filter(
        (f) => f && f.value !== null && f.value !== '' && (!Array.isArray(f.value) || f.value.length > 0)
    ).length
    const activeFilterCount = metadataFilterCount + (pendingPublicationFilter ? 1 : 0) + (unpublishedFilter ? 1 : 0) + (archivedFilter ? 1 : 0) + (fileTypeFilter && fileTypeFilter !== 'all' ? 1 : 0) + (userFilter ? 1 : 0)

    const hasFilterPillsRow =
        activeFilterCount > 0 ||
        Array.from(primaryFilterKeys).some((k) => {
            const filter = filters[k]
            return filter && filter.value !== null && filter.value !== '' && (!Array.isArray(filter.value) || filter.value.length > 0)
        })
    const hideDesktopHighlightBar =
        hideSortInSecondaryBar && !hasFilterPillsRow && barTrailingContent == null

    const resolveCount = (v) =>
        typeof v === 'number' && !Number.isNaN(v) ? v : null

    const resolvedFiltered =
        resolveCount(pageProps.filtered_grid_total) ?? resolveCount(filteredGridTotal)
    const resolvedFolder =
        resolveCount(pageProps.grid_folder_total) ?? resolveCount(gridFolderTotal)

    const hasFilteredTotal = resolvedFiltered !== null
    const hasFolderTotal = resolvedFolder !== null

    const folderDenominator = hasFolderTotal
        ? resolvedFolder
        : totalInCategory != null
          ? totalInCategory
          : assetResultCount

    const loadedCount = assetResultCount
    const showPlus =
        !hasFilteredTotal &&
        hasMoreAvailable &&
        loadedCount != null &&
        folderDenominator != null &&
        loadedCount < folderDenominator

    let countPart = ''
    if (hasFilteredTotal && hasFolderTotal) {
        countPart = `${resolvedFiltered} of ${resolvedFolder}`
    } else if (hasFilteredTotal && !hasFolderTotal) {
        if (loadedCount != null) {
            if (hasMoreAvailable && loadedCount < resolvedFiltered) {
                countPart = `${loadedCount} of ${resolvedFiltered}+`
            } else if (loadedCount === resolvedFiltered) {
                countPart = `${resolvedFiltered} ${resolvedFiltered === 1 ? 'result' : 'results'}`
            } else {
                countPart = `${loadedCount} of ${resolvedFiltered}`
            }
        } else {
            countPart = `${resolvedFiltered} ${resolvedFiltered === 1 ? 'result' : 'results'}`
        }
    } else if (loadedCount != null && folderDenominator != null) {
        countPart = `${loadedCount} of ${folderDenominator}${showPlus ? '+' : ''}`
    }

    useEffect(() => {
        if (!hideInlineMoreFiltersButton || !onToolbarMoreFiltersMetaReport) return
        const desktopResultSummary = countPart
            ? activeFilterCount > 0
                ? `${countPart} · ${activeFilterCount} filter${activeFilterCount !== 1 ? 's' : ''}`
                : countPart
            : ''
        onToolbarMoreFiltersMetaReport({
            activeFilterCount,
            visibleSecondaryFiltersLength: visibleSecondaryFilters.length,
            brandPrimary,
            desktopResultSummary,
        })
    }, [
        hideInlineMoreFiltersButton,
        onToolbarMoreFiltersMetaReport,
        activeFilterCount,
        visibleSecondaryFilters.length,
        brandPrimary,
        countPart,
    ])
    
    // Always render the "More filters" bar container
    // Content changes based on category, but bar persists
    // Show empty state if no filters available for current category
    return (
        <div>
            {/* Bar: optional inline More (when not in toolbar) + pills + Sort — desktop More lives in AssetGridToolbar */}
            <div
                className={`px-3 py-1.5 sm:px-4 flex flex-row flex-wrap items-center gap-2 sm:justify-between text-left border-b border-gray-200 min-h-[2.25rem]${hideDesktopHighlightBar ? ' lg:hidden' : ''}`}
                style={{ borderBottomWidth: '2px', borderBottomColor: brandPrimary }}
            >
                <div className="flex items-center gap-1.5 sm:gap-2 min-w-0 flex-1 flex-wrap">
                    {!hideInlineMoreFiltersButton && (
                        <MoreFiltersTriggerButton
                            isExpanded={isExpanded}
                            onToggle={() => setIsExpanded(!isExpanded)}
                            activeFilterCount={activeFilterCount}
                            brandPrimary={brandPrimary}
                            visibleSecondaryFiltersLength={visibleSecondaryFilters.length}
                            className="py-1.5 -my-1.5"
                        />
                    )}
                    {/* Active filter pills on the highlighted bar (primary + secondary + lifecycle, etc.) */}
                    {(activeFilterCount > 0 || Array.from(primaryFilterKeys).some(k => filters[k] && (filters[k].value !== null && filters[k].value !== '') && (!Array.isArray(filters[k].value) || filters[k].value.length > 0))) && (
                        <div className="flex flex-wrap items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
                            {/* Primary applied filters (e.g. Photo Type: studio) — inline with More filters */}
                            {Array.from(primaryFilterKeys).map((fieldKey) => {
                                const filter = filters[fieldKey]
                                if (!filter || filter.value === null || filter.value === '') return null
                                if (Array.isArray(filter.value) && filter.value.length === 0) return null
                                const field = (filterable_schema || []).find((f) => (f.field_key || f.key) === fieldKey)
                                let valueLabel = Array.isArray(filter.value) ? (filter.value[0] ?? '') : String(filter.value ?? '')
                                let optionColor = null
                                if (field && (field.type === 'select' || field.type === 'multiselect') && field.options?.length) {
                                    const rawVal = Array.isArray(filter.value) ? filter.value[0] : filter.value
                                    const opt = field.options.find((o) => String(o.value) === String(rawVal))
                                    if (opt) {
                                        valueLabel = opt.display_label ?? opt.label ?? valueLabel
                                        if (opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color)) optionColor = opt.color
                                    }
                                }
                                return (
                                    <span
                                        key={fieldKey}
                                        className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                                        style={appliedMetadataPillStyle}
                                    >
                                        {getFieldLabel(fieldKey)}: {optionColor ? (
                                            <span className="inline-flex items-center gap-1">
                                                <span
                                                    className="h-2 w-2 flex-shrink-0 rounded-full ring-1 ring-slate-400/35"
                                                    style={{ backgroundColor: optionColor }}
                                                />
                                                {valueLabel}
                                            </span>
                                        ) : valueLabel}
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveFilter(fieldKey)}
                                            className="opacity-90 hover:opacity-100"
                                            style={{ color: appliedMetadataPillStyle.color }}
                                            aria-label={`Remove ${fieldKey} filter`}
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </span>
                                )
                            })}
                            {pendingPublicationFilter && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-yellow-50 text-yellow-700 rounded">
                                    Pending Publication
                                    <button type="button" onClick={handlePendingPublicationFilterToggle} className="text-yellow-600 hover:text-yellow-800" aria-label="Remove filter"><XMarkIcon className="h-3 w-3" /></button>
                                </span>
                            )}
                            {unpublishedFilter && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-yellow-50 text-yellow-700 rounded">
                                    Unpublished
                                    <button type="button" onClick={handleUnpublishedFilterToggle} className="text-yellow-600 hover:text-yellow-800" aria-label="Remove filter"><XMarkIcon className="h-3 w-3" /></button>
                                </span>
                            )}
                            {archivedFilter && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-gray-50 text-gray-700 rounded">
                                    Archived
                                    <button type="button" onClick={handleArchivedFilterToggle} className="text-gray-600 hover:text-gray-800" aria-label="Remove filter"><XMarkIcon className="h-3 w-3" /></button>
                                </span>
                            )}
                            {fileTypeFilter && fileTypeFilter !== 'all' && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-blue-50 text-blue-700 rounded">
                                    File Type: {fileTypeFilter.toUpperCase()}
                                    <button type="button" onClick={() => handleFileTypeFilterChange('all')} className="text-blue-600 hover:text-blue-800" aria-label="Remove filter"><XMarkIcon className="h-3 w-3" /></button>
                                </span>
                            )}
                            {userFilter && (() => {
                                const u = findUploadedByUser(pageProps.uploaded_by_users, userFilter)
                                const label = u ? (u.name || u.email || 'User') : 'Unknown'
                                return (
                                    <span
                                        className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                                        style={appliedMetadataPillStyle}
                                    >
                                        {u ? (
                                            <Avatar
                                                avatarUrl={u.avatar_url}
                                                firstName={u.first_name}
                                                lastName={u.last_name}
                                                email={u.email}
                                                size="h-5 w-5 text-[9px]"
                                                primaryColor={brandPrimary}
                                            />
                                        ) : null}
                                        <span>Uploaded by: {label}</span>
                                        <button
                                            type="button"
                                            onClick={() => handleUserFilterChange(null)}
                                            className="opacity-90 hover:opacity-100"
                                            style={{ color: appliedMetadataPillStyle.color }}
                                            aria-label="Remove filter"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </span>
                                )
                            })()}
                            {Object.entries(filters).map(([fieldKey, filter]) => {
                                if (!filter || filter.value === null || filter.value === '') return null
                                const field = visibleSecondaryFilters.find((f) => (f.field_key || f.key) === fieldKey)
                                if (!field) return null
                                // Boolean/toggle (e.g. Starred): show "Yes"/"No" instead of "true"/"false"
                                const isBooleanToggle = fieldKey === 'starred' || ((field.type === 'boolean') && (field.display_widget === 'toggle'))
                                const rawVal = Array.isArray(filter.value) ? filter.value[0] : filter.value
                                let valueLabel = isBooleanToggle
                                    ? (rawVal === true || rawVal === 'true' || rawVal === 1 || rawVal === '1' ? 'Yes' : 'No')
                                    : (Array.isArray(filter.value) ? (filter.value[0] ?? '') : String(filter.value ?? ''))
                                // For select/multiselect: look up option label and color
                                let optionColor = null
                                if ((field.type === 'select' || field.type === 'multiselect') && field.options?.length) {
                                    const opt = field.options.find((o) => String(o.value) === String(rawVal))
                                    if (opt) {
                                        valueLabel = opt.display_label ?? opt.label ?? valueLabel
                                        if (opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color)) optionColor = opt.color
                                    }
                                }
                                return (
                                    <span
                                        key={fieldKey}
                                        className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                                        style={appliedMetadataPillStyle}
                                    >
                                        {field.display_label || field.label}: {optionColor ? (
                                            <span className="inline-flex items-center gap-1">
                                                <span
                                                    className="h-2 w-2 flex-shrink-0 rounded-full ring-1 ring-slate-400/35"
                                                    style={{ backgroundColor: optionColor }}
                                                />
                                                {valueLabel}
                                            </span>
                                        ) : valueLabel}
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveFilter(fieldKey)}
                                            className="opacity-90 hover:opacity-100"
                                            style={{ color: appliedMetadataPillStyle.color }}
                                            aria-label={`Remove ${fieldKey} filter`}
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </span>
                                )
                            })}
                        </div>
                    )}
                </div>

                {/* Right: optional trailing content + count + Sort — compact row on mobile */}
                <div className="flex items-center gap-1.5 sm:gap-2 flex-shrink-0 sm:justify-end min-w-0">
                    {barTrailingContent != null && barTrailingContent}
                    {/* Indicator: result count and filter count in selected category */}
                    {(countPart || activeFilterCount > 0) && (
                        <span
                            className={`text-xs text-gray-500 whitespace-nowrap${hideSortInSecondaryBar && activeFilterCount > 0 ? ' lg:hidden' : ''}`}
                        >
                            {[
                                countPart ||
                                    (loadedCount != null ? String(loadedCount) : ''),
                                activeFilterCount > 0 ? `${activeFilterCount} filter${activeFilterCount !== 1 ? 's' : ''}` : '',
                            ].filter(Boolean).join(' · ')}
                        </span>
                    )}
                    {/* Sort: in toolbar when hideSortInSecondaryBar (Assets / Executions / Collections) */}
                    {onSortChange && !hideSortInSecondaryBar && (
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <SortDropdown
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSortChange={onSortChange}
                            showComplianceFilter={showComplianceFilter}
                            primaryColor={brandPrimary}
                        />
                    </div>
                    )}
                </div>
            </div>
            
            {/* Expandable Container: smooth height animation (grid 0fr → 1fr) to avoid layout glitch */}
            <div
                className="grid transition-[grid-template-rows] duration-200 ease-out"
                style={{ gridTemplateRows: isExpanded ? '1fr' : '0fr' }}
            >
                <div className={isExpanded ? 'min-h-0 overflow-visible' : 'min-h-0 overflow-hidden'}>
                    <div className="space-y-4 border-t border-gray-200/80 px-3 py-4 sm:px-4">
                    {(() => {
                        const findFieldForPill = (key) =>
                            visibleSecondaryFilters.find((f) => getFieldKey(f) === key) ||
                            visiblePrimaryFilters.find((f) => getFieldKey(f) === key) ||
                            (filterable_schema || []).find((f) => getFieldKey(f) === key)

                        const renderMetadataField = (field) => {
                            const fieldKey = getFieldKey(field)
                            const currentFilter = filters[fieldKey] || {}
                            const currentValue = currentFilter.value ?? null
                            const currentOperator = currentFilter.operator ?? (field.operators?.[0]?.value || 'equals')
                            return (
                                <div key={fieldKey} className="min-w-0 [&_select]:min-h-10 [&_input:not([type='checkbox']):not([type='radio'])]:min-h-10">
                                    <FilterFieldInput
                                        field={field}
                                        value={currentValue}
                                        operator={currentOperator}
                                        availableValues={available_values[fieldKey] || []}
                                        onChange={(op, val) => handleFilterChange(fieldKey, op, val)}
                                        variant="secondary"
                                        accentColor={brandPrimary}
                                    />
                                </div>
                            )
                        }

                        const layoutCustom = layoutBuckets.custom || []
                        const layoutOther = layoutBuckets.other || []
                        const customSlice = customFieldsExpanded
                            ? layoutCustom
                            : layoutCustom.slice(0, 5)
                        const otherSlice = otherFieldsExpanded ? layoutOther : layoutOther.slice(0, 5)

                        const hasFileTypeFilterUi = gridFileTypeGroups.length > 0
                        const hasAssetSection =
                            layoutBuckets.assetProps.length > 0 ||
                            (showComplianceFilter && onComplianceFilterChange) ||
                            hasFileTypeFilterUi

                        return (
                            <>
                                {/* Active filters — pills + clear all */}
                                {(activeFilterCount > 0 || hasClearableToolbarFilters) && (
                                    <div className="rounded-xl border border-gray-200/60 bg-gray-50/50 p-3">
                                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                                <AdjustmentsHorizontalIcon className="h-4 w-4 text-gray-500" aria-hidden />
                                                Active filters
                                            </span>
                                            {hasClearableToolbarFilters ? (
                                                <button
                                                    type="button"
                                                    onClick={handleClearAllFiltersExpanded}
                                                    className="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    Clear all
                                                </button>
                                            ) : null}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {pendingPublicationFilter && (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm">
                                                    Pending Publication
                                                    <button
                                                        type="button"
                                                        onClick={handlePendingPublicationFilterToggle}
                                                        className="rounded-full p-0.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                                        aria-label="Remove"
                                                    >
                                                        <XMarkIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                </span>
                                            )}
                                            {unpublishedFilter && (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm">
                                                    Unpublished
                                                    <button
                                                        type="button"
                                                        onClick={handleUnpublishedFilterToggle}
                                                        className="rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                        aria-label="Remove"
                                                    >
                                                        <XMarkIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                </span>
                                            )}
                                            {archivedFilter && (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm">
                                                    Archived
                                                    <button
                                                        type="button"
                                                        onClick={handleArchivedFilterToggle}
                                                        className="rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                        aria-label="Remove"
                                                    >
                                                        <XMarkIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                </span>
                                            )}
                                            {fileTypeFilter && fileTypeFilter !== 'all' && (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm">
                                                    File type:{' '}
                                                    {fileTypeFilterLabelByKey.get(fileTypeFilter) || fileTypeFilter}
                                                    <button
                                                        type="button"
                                                        onClick={() => handleFileTypeFilterChange('all')}
                                                        className="rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                        aria-label="Remove"
                                                    >
                                                        <XMarkIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                </span>
                                            )}
                                            {userFilter && (() => {
                                                const u = findUploadedByUser(pageProps.uploaded_by_users, userFilter)
                                                const label = u ? (u.name || u.email || 'User') : 'Unknown'
                                                return (
                                                    <span className="inline-flex items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm">
                                                        {u ? (
                                                            <Avatar
                                                                avatarUrl={u.avatar_url}
                                                                firstName={u.first_name}
                                                                lastName={u.last_name}
                                                                email={u.email}
                                                                size="h-5 w-5 text-[9px]"
                                                                primaryColor={brandPrimary}
                                                            />
                                                        ) : null}
                                                        Uploaded by: {label}
                                                        <button
                                                            type="button"
                                                            onClick={() => handleUserFilterChange(null)}
                                                            className="rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                            aria-label="Remove"
                                                        >
                                                            <XMarkIcon className="h-3.5 w-3.5" />
                                                        </button>
                                                    </span>
                                                )
                                            })()}
                                            {Array.from(primaryFilterKeys).map((fieldKey) => {
                                                const filter = filters[fieldKey]
                                                if (!filter || filter.value === null || filter.value === '') return null
                                                if (Array.isArray(filter.value) && filter.value.length === 0) return null
                                                const field = findFieldForPill(fieldKey)
                                                if (!field) return null
                                                let valueLabel = Array.isArray(filter.value)
                                                    ? (filter.value[0] ?? '')
                                                    : String(filter.value ?? '')
                                                let optionColor = null
                                                if (
                                                    field &&
                                                    (field.type === 'select' || field.type === 'multiselect') &&
                                                    field.options?.length
                                                ) {
                                                    const rawVal = Array.isArray(filter.value) ? filter.value[0] : filter.value
                                                    const opt = field.options.find((o) => String(o.value) === String(rawVal))
                                                    if (opt) {
                                                        valueLabel = opt.display_label ?? opt.label ?? valueLabel
                                                        if (opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color)) {
                                                            optionColor = opt.color
                                                        }
                                                    }
                                                }
                                                return (
                                                    <span
                                                        key={fieldKey}
                                                        className="inline-flex max-w-full items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm"
                                                    >
                                                        {getFieldLabel(fieldKey)}:{' '}
                                                        {optionColor ? (
                                                            <span className="inline-flex items-center gap-1 truncate">
                                                                <span
                                                                    className="h-2 w-2 shrink-0 rounded-full"
                                                                    style={{ backgroundColor: optionColor }}
                                                                />
                                                                <span className="truncate">{valueLabel}</span>
                                                            </span>
                                                        ) : (
                                                            <span className="truncate">{valueLabel}</span>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveFilter(fieldKey)}
                                                            className="shrink-0 rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                            aria-label={`Remove ${fieldKey}`}
                                                        >
                                                            <XMarkIcon className="h-3.5 w-3.5" />
                                                        </button>
                                                    </span>
                                                )
                                            })}
                                            {Object.entries(filters).map(([fieldKey, filter]) => {
                                                if (!filter || filter.value === null || filter.value === '') return null
                                                if (Array.isArray(filter.value) && filter.value.length === 0) return null
                                                if (primaryFilterKeys.has(fieldKey)) return null
                                                const field = findFieldForPill(fieldKey)
                                                if (!field) return null
                                                const isBooleanToggle =
                                                    fieldKey === 'starred' ||
                                                    (field.type === 'boolean' && field.display_widget === 'toggle')
                                                const rawVal = Array.isArray(filter.value) ? filter.value[0] : filter.value
                                                let valueLabel = isBooleanToggle
                                                    ? rawVal === true ||
                                                      rawVal === 'true' ||
                                                      rawVal === 1 ||
                                                      rawVal === '1'
                                                        ? 'Yes'
                                                        : 'No'
                                                    : Array.isArray(filter.value)
                                                      ? (filter.value[0] ?? '')
                                                      : String(filter.value ?? '')
                                                let optionColor = null
                                                if (
                                                    (field.type === 'select' || field.type === 'multiselect') &&
                                                    field.options?.length
                                                ) {
                                                    const opt = field.options.find((o) => String(o.value) === String(rawVal))
                                                    if (opt) {
                                                        valueLabel = opt.display_label ?? opt.label ?? valueLabel
                                                        if (opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color)) {
                                                            optionColor = opt.color
                                                        }
                                                    }
                                                }
                                                return (
                                                    <span
                                                        key={fieldKey}
                                                        className="inline-flex max-w-full items-center gap-1 rounded-full border border-gray-200/80 bg-white px-2.5 py-1 text-xs text-gray-800 shadow-sm"
                                                    >
                                                        <span className="truncate">
                                                            {field.display_label || field.label}:{' '}
                                                            {optionColor ? (
                                                                <span className="inline-flex items-center gap-1">
                                                                    <span
                                                                        className="h-2 w-2 shrink-0 rounded-full"
                                                                        style={{ backgroundColor: optionColor }}
                                                                    />
                                                                    {valueLabel}
                                                                </span>
                                                            ) : (
                                                                valueLabel
                                                            )}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveFilter(fieldKey)}
                                                            className="shrink-0 rounded-full p-0.5 text-gray-500 hover:bg-gray-100"
                                                            aria-label={`Remove ${fieldKey}`}
                                                        >
                                                            <XMarkIcon className="h-3.5 w-3.5" />
                                                        </button>
                                                    </span>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Row 1 — primary bar (sticky) */}
                                <div className="sticky top-0 z-10 -mx-3 border-b border-gray-200/60 bg-white/95 px-3 py-3 backdrop-blur-sm sm:-mx-4 sm:px-4">
                                    <p className="mb-2 flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                        <TagIcon className="h-3.5 w-3.5" aria-hidden />
                                        Library scope
                                    </p>
                                    <div className="flex flex-nowrap items-end gap-4 overflow-x-auto pb-1 [scrollbar-width:thin]">
                                        {layoutBuckets.tagsField ? (
                                            <div className="flex min-w-[12rem] max-w-[min(100%,24rem)] flex-1 flex-col gap-1.5">
                                                <span className="text-xs font-medium text-gray-700">Tags</span>
                                                <FilterFieldInput
                                                    field={layoutBuckets.tagsField}
                                                    value={
                                                        filters[getFieldKey(layoutBuckets.tagsField)]?.value ?? null
                                                    }
                                                    operator={
                                                        filters[getFieldKey(layoutBuckets.tagsField)]?.operator ||
                                                        'in'
                                                    }
                                                    availableValues={
                                                        available_values[getFieldKey(layoutBuckets.tagsField)] || []
                                                    }
                                                    onChange={(op, val) =>
                                                        handleFilterChange(getFieldKey(layoutBuckets.tagsField), op, val)
                                                    }
                                                    variant="secondary"
                                                    accentColor={brandPrimary}
                                                />
                                            </div>
                                        ) : null}
                                        {pageProps.uploaded_by_users && pageProps.uploaded_by_users.length > 0 ? (
                                            <div className="w-[min(100%,14rem)] shrink-0">
                                                <UserSelect
                                                    users={pageProps.uploaded_by_users}
                                                    value={userFilter}
                                                    onChange={handleUserFilterChange}
                                                    placeholder="All uploaders"
                                                    label="Uploaded by"
                                                    narrow
                                                />
                                            </div>
                                        ) : null}
                                        {layoutBuckets.starredField ? (
                                            <div className="shrink-0">{renderMetadataField(layoutBuckets.starredField)}</div>
                                        ) : null}
                                        {(canPublish || canBypassApproval || canArchive) && (
                                            <div className="flex min-w-0 shrink-0 flex-col gap-1.5">
                                                <span className="text-xs font-medium text-gray-700">Lifecycle</span>
                                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    {canPublish && (
                                                        <label className="flex cursor-pointer items-center gap-1.5 whitespace-nowrap">
                                                            <input
                                                                type="checkbox"
                                                                checked={pendingPublicationFilter}
                                                                onChange={handlePendingPublicationFilterToggle}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="flex items-center gap-1 text-xs text-gray-700">
                                                                <ClockIcon className="h-3.5 w-3.5 text-gray-400" />
                                                                Pending
                                                            </span>
                                                        </label>
                                                    )}
                                                    {canBypassApproval && (
                                                        <label className="flex cursor-pointer items-center gap-1.5 whitespace-nowrap">
                                                            <input
                                                                type="checkbox"
                                                                checked={unpublishedFilter}
                                                                onChange={handleUnpublishedFilterToggle}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="flex items-center gap-1 text-xs text-gray-700">
                                                                <ClockIcon className="h-3.5 w-3.5 text-gray-400" />
                                                                Unpublished
                                                            </span>
                                                        </label>
                                                    )}
                                                    {canArchive && (
                                                        <label className="flex cursor-pointer items-center gap-1.5 whitespace-nowrap">
                                                            <input
                                                                type="checkbox"
                                                                checked={archivedFilter}
                                                                onChange={handleArchivedFilterToggle}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="flex items-center gap-1 text-xs text-gray-700">
                                                                <ArchiveBoxIcon className="h-3.5 w-3.5 text-gray-400" />
                                                                Archived
                                                            </span>
                                                        </label>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Row 2 — visual / hue */}
                                {layoutBuckets.visualFields.length > 0 ? (
                                    <div className="rounded-xl border border-gray-200/60 bg-white/60 p-4">
                                        <p className="mb-3 flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                            <SwatchIcon className="h-3.5 w-3.5" aria-hidden />
                                            Visual
                                        </p>
                                        <div className="flex flex-row flex-wrap items-start gap-4">
                                            {layoutBuckets.visualFields.map((field) => {
                                                const fieldKey = getFieldKey(field)
                                                const w = resolve(field, CONTEXT.FILTER)
                                                const wide =
                                                    w === WIDGET.COLOR_SWATCH || w === WIDGET.DOMINANT_COLORS
                                                return (
                                                    <div
                                                        key={fieldKey}
                                                        className={
                                                            wide ? 'min-w-0 w-full max-w-full sm:flex-1' : 'min-w-[10rem]'
                                                        }
                                                    >
                                                        {renderMetadataField(field)}
                                                    </div>
                                                )
                                            })}
                                        </div>
                                    </div>
                                ) : null}

                                {/* Collapsible sections */}
                                <div className="flex flex-col gap-4">
                                    {layoutBuckets.basic.length > 0 ? (
                                        <FilterCollapsibleSection
                                            title="Basic filters"
                                            icon={AdjustmentsHorizontalIcon}
                                            open={sectionBasicOpen}
                                            onToggle={() => setSectionBasicOpen((v) => !v)}
                                        >
                                            <div className={FILTER_GRID_CLASS}>{layoutBuckets.basic.map(renderMetadataField)}</div>
                                        </FilterCollapsibleSection>
                                    ) : null}

                                    {hasAssetSection ? (
                                        <FilterCollapsibleSection
                                            title="Asset properties"
                                            icon={CubeTransparentIcon}
                                            open={sectionAssetOpen}
                                            onToggle={() => setSectionAssetOpen((v) => !v)}
                                        >
                                            <div className={`${FILTER_GRID_CLASS} mb-4`}>
                                                {showComplianceFilter && onComplianceFilterChange ? (
                                                    <div className="min-w-0 space-y-1.5">
                                                        <label className="block text-xs font-medium text-gray-700">
                                                            Brand alignment
                                                        </label>
                                                        <select
                                                            value={complianceFilter || 'all'}
                                                            onChange={(e) =>
                                                                onComplianceFilterChange(
                                                                    e.target.value === 'all' ? '' : e.target.value
                                                                )
                                                            }
                                                            className="h-10 w-full rounded-lg border border-gray-300/80 bg-white px-3 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                                        >
                                                            <option value="all">All</option>
                                                            <option value="superb">Superb (≥90)</option>
                                                            <option value="strong">Strong (≥75)</option>
                                                            <option value="needs_review">Needs Review (&lt;60)</option>
                                                            <option value="failing">Failing (&lt;40)</option>
                                                            <option value="unscored">Unscored</option>
                                                        </select>
                                                    </div>
                                                ) : null}
                                                {hasFileTypeFilterUi ? (
                                                    <div className="min-w-0 space-y-1.5">
                                                        <label className="block text-xs font-medium text-gray-700">
                                                            File type
                                                        </label>
                                                        <select
                                                            value={fileTypeFilter}
                                                            onChange={(e) => handleFileTypeFilterChange(e.target.value)}
                                                            className="h-10 w-full rounded-lg border border-gray-300/80 bg-white px-3 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                        >
                                                            <option value="all">All</option>
                                                            {gridFileTypeGroups.map((grp) => (
                                                                <optgroup
                                                                    key={grp.group_key || grp.group_label}
                                                                    label={grp.group_label || grp.group_key}
                                                                >
                                                                    {(grp.types || []).map((t) => (
                                                                        <option key={t.key} value={t.key}>
                                                                            {t.label}
                                                                        </option>
                                                                    ))}
                                                                </optgroup>
                                                            ))}
                                                        </select>
                                                    </div>
                                                ) : null}
                                                {layoutBuckets.assetProps.map(renderMetadataField)}
                                            </div>
                                        </FilterCollapsibleSection>
                                    ) : null}

                                    {layoutBuckets.aiScene.length > 0 ? (
                                        <FilterCollapsibleSection
                                            title="AI / scene data"
                                            icon={SparklesIcon}
                                            open={sectionAiOpen}
                                            onToggle={() => setSectionAiOpen((v) => !v)}
                                        >
                                            <div className={FILTER_GRID_CLASS}>
                                                {layoutBuckets.aiScene.map(renderMetadataField)}
                                            </div>
                                        </FilterCollapsibleSection>
                                    ) : null}

                                    {layoutCustom.length > 0 ? (
                                        <FilterCollapsibleSection
                                            title="Custom fields"
                                            icon={RectangleStackIcon}
                                            open={sectionCustomOpen}
                                            onToggle={() => setSectionCustomOpen((v) => !v)}
                                        >
                                            <div className={FILTER_GRID_CLASS}>
                                                {customSlice.map(renderMetadataField)}
                                            </div>
                                            {layoutCustom.length > 5 ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setCustomFieldsExpanded((v) => !v)}
                                                    className="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    {customFieldsExpanded
                                                        ? 'Show less'
                                                        : `Show more (${layoutCustom.length - 5} hidden)`}
                                                </button>
                                            ) : null}
                                        </FilterCollapsibleSection>
                                    ) : null}

                                    {layoutOther.length > 0 ? (
                                        <FilterCollapsibleSection
                                            title="Other fields"
                                            icon={EllipsisHorizontalCircleIcon}
                                            open={sectionOtherOpen}
                                            onToggle={() => setSectionOtherOpen((v) => !v)}
                                        >
                                            <div className={FILTER_GRID_CLASS}>
                                                {otherSlice.map(renderMetadataField)}
                                            </div>
                                            {layoutOther.length > 5 ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setOtherFieldsExpanded((v) => !v)}
                                                    className="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    {otherFieldsExpanded
                                                        ? 'Show less'
                                                        : `Show more (${layoutOther.length - 5} hidden)`}
                                                </button>
                                            ) : null}
                                        </FilterCollapsibleSection>
                                    ) : null}
                                </div>

                                {visibleSecondaryFilters.length === 0 &&
                                visiblePrimaryFilters.length === 0 &&
                                !layoutBuckets.tagsField ? (
                                    <div className="rounded-lg border border-dashed border-gray-200/80 py-8 text-center text-sm text-gray-500">
                                        No filters available for this folder
                                    </div>
                                ) : null}
                            </>
                        )
                    })()}
                    
                    {/* Hidden Filter Awareness Message */}
                    {/* Only render when:
                        1. Secondary filters are expanded (isExpanded === true)
                        2. There are secondary filters total (secondaryFilters.length > 0)
                        3. There are hidden filters (hiddenFilterCount > 0)
                    */}
                    {hiddenFilterCount > 0 && secondaryFilters.length > 0 && (
                        <>
                            {/* Subtle divider */}
                            <div className="mt-4 pt-4 border-t border-gray-200">
                                <p className="text-xs text-gray-500 text-center">
                                    {hiddenFilterCount} {hiddenFilterCount === 1 ? 'filter' : 'filters'} hidden — no matching values
                                </p>
                            </div>
                        </>
                    )}
                    
                    {/* Add Field CTA */}
                    {/* Only render when:
                        1. Secondary filters are expanded (isExpanded === true)
                        2. category_id !== null (NOT "All Categories")
                        3. User has permission to manage fields (canManageFields === true)
                        
                        Note: CTA is category-scoped because metadata fields are created per category.
                        Fields cannot be created in "All Categories" context (no category context).
                    */}
                    {selectedCategoryId !== null && canManageFields && (
                        <div className="mt-4 pt-4 border-t border-gray-200">
                            <Link
                                href={`/app/tenant/metadata/registry?category_id=${selectedCategoryId}&asset_type=${assetType}`}
                                className="inline-flex items-center gap-1.5 text-xs text-gray-600 hover:text-indigo-600 transition-colors"
                            >
                                <PlusIcon className="h-4 w-4" />
                                <span>Add filter to this folder</span>
                            </Link>
                        </div>
                    )}
                    </div>
                </div>
            </div>
        </div>
    )
}
