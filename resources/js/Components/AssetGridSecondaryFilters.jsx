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
 * - No backend changes
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

import { useState, useEffect, useMemo } from 'react'
import { usePage, router, Link } from '@inertiajs/react'
import { ChevronDownIcon, ChevronUpIcon, FunnelIcon, XMarkIcon, PlusIcon, ClockIcon, ArchiveBoxIcon, UserIcon } from '@heroicons/react/24/outline'
import SortDropdown from './SortDropdown'
import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
import { getSecondaryFilters, getPrimaryFilters } from '../utils/filterTierResolver'
import { getVisibleFilters, getHiddenFilters, getHiddenFilterCount, getFilterVisibilityState } from '../utils/filterVisibilityRules'
import { isFilterCompatible } from '../utils/filterScopeRules'
import { parseFiltersFromUrl, buildUrlParamsWithFlatFilters, normalizeFilterParam } from '../utils/filterUrlUtils'
import { FilterFieldInput } from './FilterFieldInput'
import { usePermission } from '../hooks/usePermission'
import UserSelect from './UserSelect'

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
 * @param {number} [props.totalInCategory] - Total assets loaded so far (for "x of y" display)
 * @param {boolean} [props.hasMoreAvailable] - If true, show "x of y+" when more can be loaded
 * @param {React.ReactNode} [props.barTrailingContent] - Optional content on the right of the bar (same line as count and Sort), e.g. Select Multiple / Select all
 * @param {boolean} [props.showComplianceFilter] - If true, show Brand DNA compliance filter (Deliverables only)
 * @param {string} [props.complianceFilter] - Current compliance filter value
 * @param {Function} [props.onComplianceFilterChange] - (value) => void
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
    hasMoreAvailable = false,
    barTrailingContent = null,
    showComplianceFilter = false,
    complianceFilter = '',
    onComplianceFilterChange = null,
}) {
    const pageProps = usePage().props
    const { auth, available_file_types = [] } = pageProps
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    
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
            only: ['assets', 'next_page_url', 'filters'],
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
            only: ['assets', 'next_page_url', 'filters'],
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
            only: ['assets', 'next_page_url', 'filters'],
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
            only: ['assets', 'next_page_url', 'filters'],
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
            only: ['assets', 'next_page_url', 'filters'],
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
        // Metadata filters use file type ('image', 'video', 'document') for asset_type compatibility
        // The normalizedConfig.asset_type might be organizational ('asset', 'deliverable')
        // For metadata field compatibility, we need to use 'image' as the file type
        // since most assets are images and metadata schema is resolved with file type
        return {
            category_id: normalizedConfig.category_id,
            asset_type: 'image',
            available_values: normalizedConfig.available_values,
        };
    }, [normalizedConfig])
    
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
    
    const page = usePage()
    const serverFilters = page.props.filters
    const [isExpanded, setIsExpanded] = useState(false)

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
            return parseFiltersFromUrl(urlParams, filterKeys)
        } catch (e) { /* ignore */ }
        return {}
    })
    
    useEffect(() => {
        if (serverFilters && typeof serverFilters === 'object' && Object.keys(serverFilters).length > 0) {
            setFilters(normalizeIncomingFilters(serverFilters))
            return
        }
        const search = typeof window !== 'undefined' ? window.location.search : (page.url?.includes('?') ? '?' + (page.url || '').split('?')[1] : '')
        const urlParams = new URLSearchParams(search)
        setFilters(parseFiltersFromUrl(urlParams, filterKeys))
    }, [page.url, page.props.filters, filterKeys])
    
    const handleFilterChange = (fieldKey, operator, value) => {
        const newFilters = { ...filters, [fieldKey]: { operator, value } }
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            delete newFilters[fieldKey]
        }
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys)
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url', 'filters'],
        })
    }
    
    const handleRemoveFilter = (fieldKey) => {
        const newFilters = { ...filters }
        delete newFilters[fieldKey]
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys)
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url', 'filters'],
        })
    }
    
    // Count active filters (including lifecycle filters and user filter)
    const metadataFilterCount = Object.values(filters).filter(
        (f) => f && f.value !== null && f.value !== '' && (!Array.isArray(f.value) || f.value.length > 0)
    ).length
    const activeFilterCount = metadataFilterCount + (pendingPublicationFilter ? 1 : 0) + (unpublishedFilter ? 1 : 0) + (archivedFilter ? 1 : 0) + (fileTypeFilter && fileTypeFilter !== 'all' ? 1 : 0) + (userFilter ? 1 : 0)
    
    // Always render the "More filters" bar container
    // Content changes based on category, but bar persists
    // Show empty state if no filters available for current category
    return (
        <div>
            {/* Bar: More filters (left) + active filter pills (center) + Sort (right) — 1 row on mobile */}
            <div
                className="px-3 py-1.5 sm:px-4 flex flex-row flex-wrap items-center gap-2 sm:justify-between text-left border-b border-gray-200 min-h-[2.25rem]"
                style={{ borderBottomWidth: '2px', borderBottomColor: brandPrimary }}
            >
                <div className="flex items-center gap-1.5 sm:gap-2 min-w-0 flex-1 flex-wrap">
                    <button
                        type="button"
                        onClick={() => setIsExpanded(!isExpanded)}
                        className="flex items-center gap-1.5 sm:gap-2 min-w-0 hover:bg-gray-50 rounded focus:outline-none focus:ring-2 focus:ring-inset py-1.5 -my-1.5 px-1 text-left"
                        style={{ ['--tw-ring-color']: brandPrimary }}
                        aria-label={isExpanded ? 'Collapse filters' : 'Expand more filters'}
                    >
                        <FunnelIcon className="h-4 w-4 text-gray-400 flex-shrink-0" aria-hidden />
                        <span className="text-sm font-medium text-gray-700 truncate hidden sm:inline">
                            More filters
                        </span>
                        {activeFilterCount > 0 && (
                            <span className="inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-medium text-white rounded-full flex-shrink-0" style={{ backgroundColor: brandPrimary }}>
                                {activeFilterCount}
                            </span>
                        )}
                        {visibleSecondaryFilters.length === 0 && selectedCategoryId && (
                            <span className="text-xs text-gray-500 italic truncate hidden sm:inline">
                                (No filters available for this category)
                            </span>
                        )}
                        {visibleSecondaryFilters.length === 0 && !selectedCategoryId && (
                            <span className="text-xs text-gray-500 italic truncate hidden sm:inline">
                                (No metadata filters for All)
                            </span>
                        )}
                        {visibleSecondaryFilters.length > 0 && (
                            <span className="flex-shrink-0 text-gray-400">
                                {isExpanded ? (
                                    <ChevronUpIcon className="h-4 w-4" aria-hidden />
                                ) : (
                                    <ChevronDownIcon className="h-4 w-4" aria-hidden />
                                )}
                            </span>
                        )}
                    </button>
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
                                    <span key={fieldKey} className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-indigo-50 text-indigo-700 rounded">
                                        {getFieldLabel(fieldKey)}: {optionColor ? (
                                            <span className="inline-flex items-center gap-1">
                                                <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: optionColor }} />
                                                {valueLabel}
                                            </span>
                                        ) : valueLabel}
                                        <button type="button" onClick={() => handleRemoveFilter(fieldKey)} className="text-indigo-600 hover:text-indigo-800" aria-label={`Remove ${fieldKey} filter`}><XMarkIcon className="h-3 w-3" /></button>
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
                            {userFilter && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-indigo-50 text-indigo-700 rounded">
                                    Created By: {pageProps.uploaded_by_users?.find(u => u.id === parseInt(userFilter))?.name || pageProps.uploaded_by_users?.find(u => u.id === parseInt(userFilter))?.email || 'Unknown'}
                                    <button type="button" onClick={() => handleUserFilterChange(null)} className="text-indigo-600 hover:text-indigo-800" aria-label="Remove filter"><XMarkIcon className="h-3 w-3" /></button>
                                </span>
                            )}
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
                                    <span key={fieldKey} className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-indigo-50 text-indigo-700 rounded">
                                        {field.display_label || field.label}: {optionColor ? (
                                            <span className="inline-flex items-center gap-1">
                                                <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: optionColor }} />
                                                {valueLabel}
                                            </span>
                                        ) : valueLabel}
                                        <button type="button" onClick={() => handleRemoveFilter(fieldKey)} className="text-indigo-600 hover:text-indigo-800" aria-label={`Remove ${fieldKey} filter`}><XMarkIcon className="h-3 w-3" /></button>
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
                    {(assetResultCount != null || activeFilterCount > 0) && (
                        <span className="text-xs text-gray-500 whitespace-nowrap">
                            {[
                                assetResultCount != null
                                    ? (totalInCategory != null
                                        ? `${assetResultCount} of ${totalInCategory}${hasMoreAvailable ? '+' : ''}`
                                        : String(assetResultCount))
                                    : '',
                                activeFilterCount > 0 ? `${activeFilterCount} filter${activeFilterCount !== 1 ? 's' : ''}` : '',
                            ].filter(Boolean).join(' · ')}
                        </span>
                    )}
                    {/* Sort: Tailwind dropdown with criteria + direction at bottom */}
                    {onSortChange && (
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
                <div className="min-h-0 overflow-hidden">
                    <div className="px-3 py-3 sm:px-4 border-t border-gray-200">
                    {/* Primary metadata filters (e.g. Logo Type) — mobile only; desktop shows them in toolbar */}
                    {visiblePrimaryFilters.length > 0 && (
                        <div className="mb-3 pb-3 border-b border-gray-200 lg:hidden">
                            <label className="text-xs font-medium text-gray-700 mb-2 block" style={{ paddingLeft: '0' }}>Primary Filters</label>
                            <div className="grid grid-cols-1 gap-3">
                                {visiblePrimaryFilters.map((field) => {
                                    const fieldKey = field.field_key || field.key
                                    const currentFilter = filters[fieldKey] || {}
                                    const currentValue = currentFilter.value ?? null
                                    const currentOperator = currentFilter.operator ?? (field.operators?.[0]?.value || 'equals')
                                    return (
                                        <FilterFieldInput
                                            key={fieldKey}
                                            field={field}
                                            value={currentValue}
                                            operator={currentOperator}
                                            availableValues={available_values[fieldKey] || []}
                                            onChange={(operator, value) => handleFilterChange(fieldKey, operator, value)}
                                            variant="secondary"
                                        />
                                    )
                                })}
                            </div>
                        </div>
                    )}
                    {/* Brand DNA Compliance Filter - Deliverables only */}
                    {showComplianceFilter && onComplianceFilterChange && (
                        <div className="mb-3 pb-3 border-b border-gray-200">
                            <label className="text-xs font-medium text-gray-700 mb-2 block" style={{ paddingLeft: '0' }}>Brand Alignment</label>
                            <select
                                value={complianceFilter || 'all'}
                                onChange={(e) => onComplianceFilterChange(e.target.value === 'all' ? '' : e.target.value)}
                                className="rounded border border-gray-300 bg-white py-1.5 pl-2 pr-8 text-xs text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="all">All</option>
                                <option value="superb">Superb (≥90)</option>
                                <option value="strong">Strong (≥75)</option>
                                <option value="needs_review">Needs Review (&lt;60)</option>
                                <option value="failing">Failing (&lt;40)</option>
                                <option value="unscored">Unscored</option>
                            </select>
                        </div>
                    )}
                    {/* Phase L.5.1: Lifecycle Filters - All three filters */}
                    {/* SECURITY: Only available to users with appropriate permissions */}
                    {(canPublish || canBypassApproval || canArchive) && (
                        <div className="mb-3 pb-3 border-b border-gray-200">
                            <label className="text-xs font-medium text-gray-700 mb-2 block" style={{ paddingLeft: '0' }}>Lifecycle</label>
                            <div className="flex flex-col gap-2">
                                {/* Pending Publication filter - requires asset.publish */}
                                {/* Phase J.3.1: Uses pending_publication (not pending_approval) to match dashboard links and backend */}
                                {canPublish && (
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={pendingPublicationFilter}
                                            onChange={handlePendingPublicationFilterToggle}
                                            className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                        />
                                        <span className="text-sm text-gray-700 flex items-center gap-1.5">
                                            <ClockIcon className="h-4 w-4 text-gray-400" />
                                            Pending Publication
                                        </span>
                                    </label>
                                )}
                                {/* Unpublished filter - requires metadata.bypass_approval */}
                                {canBypassApproval && (
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={unpublishedFilter}
                                            onChange={handleUnpublishedFilterToggle}
                                            className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                        />
                                        <span className="text-sm text-gray-700 flex items-center gap-1.5">
                                            <ClockIcon className="h-4 w-4 text-gray-400" />
                                            Unpublished
                                        </span>
                                    </label>
                                )}
                                {/* Archived filter - requires asset.archive */}
                                {canArchive && (
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={archivedFilter}
                                            onChange={handleArchivedFilterToggle}
                                            className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                        />
                                        <span className="text-sm text-gray-700 flex items-center gap-1.5">
                                            <ArchiveBoxIcon className="h-4 w-4 text-gray-400" />
                                            Archived
                                        </span>
                                    </label>
                                )}
                            </div>
                        </div>
                    )}
                    
                    {/* File Type Filter */}
                    {available_file_types && available_file_types.length > 0 && (
                        <div className="mb-3 pb-3 border-b border-gray-200">
                            <label className="block text-xs font-medium text-gray-700 mb-1">
                                File Type
                            </label>
                            <select
                                value={fileTypeFilter}
                                onChange={(e) => handleFileTypeFilterChange(e.target.value)}
                                className="w-full px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="all">All</option>
                                {available_file_types.map((fileType) => (
                                    <option key={fileType} value={fileType}>
                                        {fileType.toUpperCase()}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    
                    {/* User Filter - Created By */}
                    {pageProps.uploaded_by_users && pageProps.uploaded_by_users.length > 0 && (
                        <div className="mb-3 pb-3 border-b border-gray-200">
                            <UserSelect
                                users={pageProps.uploaded_by_users}
                                value={userFilter}
                                onChange={handleUserFilterChange}
                                placeholder="All Users"
                                label="Created By"
                            />
                        </div>
                    )}
                    
                    {/* Active Filters (if any) */}
                    {activeFilterCount > 0 && (
                        <div className="mb-3">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-xs font-medium text-gray-700">Active Filters</span>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {/* Phase L.5.1: Show lifecycle filter chips if active */}
                                {pendingPublicationFilter && (
                                    <div className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-yellow-50 text-yellow-700 rounded">
                                        <span>Pending Publication</span>
                                        <button
                                            type="button"
                                            onClick={handlePendingPublicationFilterToggle}
                                            className="text-yellow-600 hover:text-yellow-800"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </div>
                                )}
                                {unpublishedFilter && (
                                    <div className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-yellow-50 text-yellow-700 rounded">
                                        <span>Unpublished</span>
                                        <button
                                            type="button"
                                            onClick={handleUnpublishedFilterToggle}
                                            className="text-yellow-600 hover:text-yellow-800"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </div>
                                )}
                                {archivedFilter && (
                                    <div className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-gray-50 text-gray-700 rounded">
                                        <span>Archived</span>
                                        <button
                                            type="button"
                                            onClick={handleArchivedFilterToggle}
                                            className="text-gray-600 hover:text-gray-800"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </div>
                                )}
                                {fileTypeFilter && fileTypeFilter !== 'all' && (
                                    <div className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded">
                                        <span>File Type: {fileTypeFilter.toUpperCase()}</span>
                                        <button
                                            type="button"
                                            onClick={() => handleFileTypeFilterChange('all')}
                                            className="text-blue-600 hover:text-blue-800"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </div>
                                )}
                                {userFilter && (
                                    <div className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded">
                                        <span>
                                            Created By: {pageProps.uploaded_by_users?.find(u => u.id === parseInt(userFilter))?.name || 
                                                         pageProps.uploaded_by_users?.find(u => u.id === parseInt(userFilter))?.email || 
                                                         'Unknown User'}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => handleUserFilterChange(null)}
                                            className="text-indigo-600 hover:text-indigo-800"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </div>
                                )}
                                {Object.entries(filters).map(([fieldKey, filter]) => {
                                    if (!filter || filter.value === null || filter.value === '') {
                                        return null
                                    }
                                    const field = visibleSecondaryFilters.find(
                                        (f) => (f.field_key || f.key) === fieldKey
                                    )
                                    if (!field) return null
                                    
                                    return (
                                        <div
                                            key={fieldKey}
                                            className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded"
                                        >
                                            <span>
                                                {field.display_label || field.label}: {(() => {
                                                    const rawVal = Array.isArray(filter.value) ? filter.value[0] : filter.value
                                                    if ((field.type === 'select' || field.type === 'multiselect') && field.options?.length) {
                                                        const opt = field.options.find((o) => String(o.value) === String(rawVal))
                                                        if (opt) {
                                                            const label = opt.display_label ?? opt.label ?? String(filter.value)
                                                            return opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color) ? (
                                                                <span className="inline-flex items-center gap-1">
                                                                    <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: opt.color }} />
                                                                    {label}
                                                                </span>
                                                            ) : label
                                                        }
                                                    }
                                                    return String(filter.value)
                                                })()}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => handleRemoveFilter(fieldKey)}
                                                className="text-indigo-600 hover:text-indigo-800"
                                            >
                                                <XMarkIcon className="h-3 w-3" />
                                            </button>
                                        </div>
                                    )
                                })}
                            </div>
                        </div>
                    )}
                    
                    {/* Filter Fields Grid */}
                    {visibleSecondaryFilters.length > 0 ? (
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            {visibleSecondaryFilters.map((field) => {
                            const fieldKey = field.field_key || field.key
                            const currentFilter = filters[fieldKey] || {}
                            const currentValue = currentFilter.value ?? null
                            const currentOperator = currentFilter.operator ?? (field.operators?.[0]?.value || 'equals')
                            
                            return (
                                <FilterFieldInput
                                    key={fieldKey}
                                    field={field}
                                    value={currentValue}
                                    operator={currentOperator}
                                    availableValues={available_values[fieldKey] || []}
                                    onChange={(operator, value) => handleFilterChange(fieldKey, operator, value)}
                                    variant="secondary"
                                />
                            )
                            })}
                        </div>
                    ) : (
                        <div className="text-sm text-gray-500 text-center py-4">
                            No filters available for this category
                        </div>
                    )}
                    
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
                                <span>Add field to this category</span>
                            </Link>
                        </div>
                    )}
                    </div>
                </div>
            </div>
        </div>
    )
}
