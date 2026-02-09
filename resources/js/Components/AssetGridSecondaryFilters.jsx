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
import { ChevronDownIcon, ChevronUpIcon, FunnelIcon, XMarkIcon, PlusIcon, ClockIcon, ArchiveBoxIcon, UserIcon, BarsArrowDownIcon, BarsArrowUpIcon } from '@heroicons/react/24/outline'
import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
import { getSecondaryFilters } from '../utils/filterTierResolver'
import { getVisibleFilters, getHiddenFilters, getHiddenFilterCount, getFilterVisibilityState } from '../utils/filterVisibilityRules'
import { isFilterCompatible } from '../utils/filterScopeRules'
import DominantColorsFilter from './DominantColorsFilter'
import ColorSwatchFilter from './ColorSwatchFilter'
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
 * @param {string} [props.sortBy] - Current sort field (starred | created | quality)
 * @param {string} [props.sortDirection] - asc | desc
 * @param {Function} [props.onSortChange] - (sortBy, sortDirection) => void
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
}) {
    const pageProps = usePage().props
    const { auth, available_file_types = [] } = pageProps
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    
    // Phase L.5.1: Check permissions for lifecycle filters
    // Pending Publication (pending_publication) requires asset.publish
    // Unpublished requires metadata.bypass_approval
    // Archived requires asset.archive
    const { hasPermission: canPublish } = usePermission('asset.publish')
    const { hasPermission: canBypassApproval } = usePermission('metadata.bypass_approval')
    const { hasPermission: canArchive } = usePermission('asset.archive')
    
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
            only: ['assets'],
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
            only: ['assets'],
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
            only: ['assets'],
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
            only: ['assets'],
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
            only: ['assets'],
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
        const context = {
            category_id: normalizedConfig.category_id,
            asset_type: 'image', // Use file type for metadata filter compatibility
            available_values: normalizedConfig.available_values,
        };
        console.log('[AssetGridSecondaryFilters] DEBUG - visibilityContext:', context);
        return context;
    }, [normalizedConfig])
    
    const visibleSecondaryFilters = useMemo(() => {
        // DEBUG: Log to understand why filters aren't showing
        console.log('[AssetGridSecondaryFilters] DEBUG - filterable_schema:', filterable_schema)
        console.log('[AssetGridSecondaryFilters] DEBUG - selectedCategoryId:', selectedCategoryId)
        console.log('[AssetGridSecondaryFilters] DEBUG - available_values:', available_values)
        console.log('[AssetGridSecondaryFilters] DEBUG - secondaryFilters:', secondaryFilters)
        console.log('[AssetGridSecondaryFilters] DEBUG - visibilityContext:', visibilityContext)
        
        // Extract .field from FilterClassification objects
        // For secondary filters: Show ALL scope-compatible filters, regardless of available_values
        // available_values will be used to limit OPTIONS within each filter, not to hide filters
        // This ensures filters are always visible if they're enabled in management
        const filterFields = secondaryFilters.map(classification => classification.field || classification)
        
        console.log('[AssetGridSecondaryFilters] DEBUG - filterFields:', filterFields)
        
        // Filter by scope compatibility only (category/asset_type), NOT by available_values
        // Rule: If a filter is enabled in management and is scope-compatible, it should show
        // available_values only limits the options dropdown, not filter visibility
        const scopeCompatibleFilters = filterFields.filter(field => {
            // Check category/asset_type compatibility only
            // Metadata fields have category_ids: null (applies to all categories)
            // So they should be compatible with any selected category
            const fieldKey = field.field_key || field.key;
            const compatible = isFilterCompatible(field, visibilityContext);
            console.log('[AssetGridSecondaryFilters] DEBUG - field compatibility check:', {
                field_key: fieldKey,
                isFilterCompatible: compatible,
                field_category_ids: field.category_ids,
                field_category_ids_type: typeof field.category_ids,
                field_asset_types: field.asset_types,
                field_asset_types_type: typeof field.asset_types,
                field_is_global: field.is_global,
                context_category_id: visibilityContext.category_id,
                context_asset_type: visibilityContext.asset_type,
                full_field: field,
            });
            return compatible;
        })
        
        console.log('[AssetGridSecondaryFilters] DEBUG - scopeCompatibleFilters:', scopeCompatibleFilters)
        console.log('[AssetGridSecondaryFilters] DEBUG - visibleSecondaryFilters count:', scopeCompatibleFilters.length)
        
        // Return all scope-compatible filters (regardless of available_values)
        // The FilterFieldInput component will handle limiting options based on available_values
        // This ensures "More filters" button is always clickable when filters are enabled
        return scopeCompatibleFilters
    }, [secondaryFilters, visibilityContext, filterable_schema, selectedCategoryId, available_values])
    
    // Get hidden secondary filter count using existing helper
    // This is used for awareness messaging only (does not reveal hidden filters)
    const hiddenFilterCount = useMemo(() => {
        return getHiddenFilterCount(secondaryFilters, visibilityContext)
    }, [secondaryFilters, visibilityContext])
    
    // UI-only expand/collapse state (not persisted to URL)
    const [isExpanded, setIsExpanded] = useState(false)
    
    // Filter state (stored in URL query params)
    const [filters, setFilters] = useState({})
    
    // Load filters from URL params on mount
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search)
        const filtersParam = urlParams.get('filters')
        if (filtersParam) {
            try {
                setFilters(JSON.parse(decodeURIComponent(filtersParam)))
            } catch (e) {
                console.error('[AssetGridSecondaryFilters] Failed to parse filters from URL', e)
            }
        }
    }, [])
    
    // Update filter value and immediately update URL
    const handleFilterChange = (fieldKey, operator, value) => {
        const newFilters = {
            ...filters,
            [fieldKey]: { operator, value },
        }
        
        // Remove filter if value is empty/null
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            delete newFilters[fieldKey]
        }
        
        setFilters(newFilters)
        
        // Update URL immediately (no Apply button)
        const urlParams = new URLSearchParams(window.location.search)
        
        // Remove existing filters param
        urlParams.delete('filters')
        
        // Add new filters if any
        const activeFilters = Object.entries(newFilters).filter(([_, filter]) => {
            return filter && filter.value !== null && filter.value !== '' && 
                   (!Array.isArray(filter.value) || filter.value.length > 0)
        })
        
        if (activeFilters.length > 0) {
            const filtersObj = {}
            activeFilters.forEach(([key, filter]) => {
                filtersObj[key] = {
                    operator: filter.operator,
                    value: filter.value,
                }
            })
            urlParams.set('filters', JSON.stringify(filtersObj))
        }
        
        // Update URL without full page reload
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: ['assets'], // Only reload assets
        })
    }
    
    // Remove a single filter
    const handleRemoveFilter = (fieldKey) => {
        const newFilters = { ...filters }
        delete newFilters[fieldKey]
        setFilters(newFilters)
        
        // Update URL immediately
        const urlParams = new URLSearchParams(window.location.search)
        urlParams.delete('filters')
        
        const activeFilters = Object.entries(newFilters).filter(([_, filter]) => {
            return filter && filter.value !== null && filter.value !== '' && 
                   (!Array.isArray(filter.value) || filter.value.length > 0)
        })
        
        if (activeFilters.length > 0) {
            const filtersObj = {}
            activeFilters.forEach(([key, filter]) => {
                filtersObj[key] = {
                    operator: filter.operator,
                    value: filter.value,
                }
            })
            urlParams.set('filters', JSON.stringify(filtersObj))
        }
        
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: ['assets'],
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
            {/* Bar: More filters (left) + Sort (right, compact) */}
            <div
                className="px-4 py-2 sm:px-6 flex items-center justify-between gap-3 text-left border-b border-gray-200"
                style={{ borderBottomWidth: '2px', borderBottomColor: brandPrimary }}
            >
                <button
                    type="button"
                    onClick={() => setIsExpanded(!isExpanded)}
                    className="flex items-center gap-2 min-w-0 flex-1 hover:bg-gray-50 rounded focus:outline-none focus:ring-2 focus:ring-inset py-1.5 -my-1.5 px-1 text-left"
                    style={{ ['--tw-ring-color']: brandPrimary }}
                >
                    <FunnelIcon className="h-4 w-4 text-gray-400 flex-shrink-0" />
                    <span className="text-sm font-medium text-gray-700 truncate">
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
                        <span className="flex-shrink-0 text-gray-400 ml-auto">
                            {isExpanded ? (
                                <ChevronUpIcon className="h-4 w-4" aria-hidden />
                            ) : (
                                <ChevronDownIcon className="h-4 w-4" aria-hidden />
                            )}
                        </span>
                    )}
                </button>

                {/* Sort: compact dropdown + direction (in filter bar) */}
                {onSortChange && (
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <span className="text-xs font-medium text-gray-500 hidden sm:inline">Sort</span>
                        <label htmlFor="more-filters-sort-by" className="sr-only">Sort by</label>
                        <select
                            id="more-filters-sort-by"
                            value={sortBy}
                            onChange={(e) => onSortChange(e.target.value, sortDirection)}
                            className="rounded border border-gray-300 bg-white py-1 pl-2 pr-6 text-xs text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            aria-label="Sort by"
                        >
                            <option value="starred">Starred</option>
                            <option value="created">Created</option>
                            <option value="quality">Quality</option>
                        </select>
                        <button
                            type="button"
                            onClick={() => onSortChange(sortBy, sortDirection === 'asc' ? 'desc' : 'asc')}
                            className="p-1 rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                            title={sortDirection === 'asc' ? 'Descending' : 'Ascending'}
                            aria-label={sortDirection === 'asc' ? 'Sort descending' : 'Sort ascending'}
                        >
                            {sortDirection === 'asc' ? (
                                <BarsArrowUpIcon className="h-4 w-4" />
                            ) : (
                                <BarsArrowDownIcon className="h-4 w-4" />
                            )}
                        </button>
                    </div>
                )}
            </div>
            
            {/* Expandable Container (inline expansion - pushes content down) */}
            {isExpanded && (
                <div className="px-4 py-4 sm:px-6 border-t border-gray-200">
                    {/* Phase L.5.1: Lifecycle Filters - All three filters */}
                    {/* SECURITY: Only available to users with appropriate permissions */}
                    {(canPublish || canBypassApproval || canArchive) && (
                        <div className="mb-4 pb-4 border-b border-gray-200">
                            <label className="text-xs font-medium text-gray-700 mb-3 block" style={{ paddingLeft: '0' }}>Lifecycle</label>
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
                        <div className="mb-4 pb-4 border-b border-gray-200">
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
                        <div className="mb-4 pb-4 border-b border-gray-200">
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
                        <div className="mb-4">
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
                                                {field.display_label || field.label}: {String(filter.value)}
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
            )}
        </div>
    )
}

/**
 * Filter Field Input Component
 * 
 * Renders a single filter field input based on field type.
 * Only shows options that exist in available_values (computed from current asset set).
 */
function FilterFieldInput({ field, value, operator, onChange, availableValues = [] }) {
    const fieldKey = field.field_key || field.key
    const fieldType = field.type || 'text'
    
    // STEP 5: Dev warning if dominant_color_bucket rendered without color filter_type
    if (fieldKey === 'dominant_color_bucket' && field.filter_type !== 'color') {
        console.error('[AssetGridSecondaryFilters] dominant_color_bucket rendered without color filter_type', field)
    }
    
    // STEP 2 & 3: Color swatch filters (filter_type === 'color') - no operator dropdown, always equals, OR semantics
    const isColorFilter = field.filter_type === 'color'
    // C9.2: Collection = single select only, no operator dropdown (no "Contains any")
    const isCollectionFilter = fieldKey === 'collection'
    // Boolean with display_widget=toggle (e.g. Starred) — render as toggle, no operator dropdown
    const isToggleBoolean = (fieldKey === 'starred' || field.display_widget === 'toggle') && fieldType === 'boolean'

    // Filter options to only show values that exist in available_values
    // This ensures users only see options that actually have matching assets
    const filteredOptions = useMemo(() => {
        if (!field.options || !Array.isArray(field.options)) {
            return null // null means "use all options" (no filtering applied)
        }
        
        // If no available values provided, show all options (fallback)
        if (!availableValues || availableValues.length === 0) {
            return null // null means "use all options"
        }
        
        // Filter options to only those with values in available_values
        // available_values contains the actual values used in assets (e.g., 'action', 'lifestyle')
        // Options may have value, option_id, or both - check all possible formats
        const filtered = field.options.filter(option => {
            const optionValue = option.value
            const optionId = option.option_id
            // Check if either the value or option_id matches any available value
            // Convert to string for comparison to handle number/string mismatches
            return availableValues.some(av => 
                String(av) === String(optionValue) || 
                (optionId !== undefined && String(av) === String(optionId))
            )
        })
        
        // If filtering resulted in no matches, fall back to all options
        // This prevents empty dropdowns when there's a value format mismatch
        if (filtered.length === 0) {
            return null // null means "use all options" (fallback)
        }
        
        return filtered
    }, [field.options, availableValues, fieldKey])
    
    const handleOperatorChange = (e) => {
        onChange(e.target.value, value)
    }
    
    // STEP 3: For color filters, always use 'equals' operator and ensure value is array (OR semantics)
    const handleValueChange = (newValue) => {
        if (isColorFilter) {
            // Color filters: handle both object format { operator, value } and legacy value-only format
            if (newValue && typeof newValue === 'object' && 'operator' in newValue && 'value' in newValue) {
                // ColorSwatchFilter emits full payload: { operator: 'equals', value: [...] }
                onChange(newValue.operator, newValue.value)
            } else {
                // Legacy format: just the value
                const arrayValue = Array.isArray(newValue) ? newValue : (newValue != null ? [newValue] : null)
                onChange('equals', arrayValue)
            }
        } else if (isCollectionFilter) {
            // Collection: single select, always use 'equals'
            onChange('equals', newValue)
        } else if (isToggleBoolean) {
            onChange('equals', newValue)
        } else {
            onChange(operator, newValue)
        }
    }
    
    // STEP 3: For color filters, hardcode operator to 'equals' and normalize value to array
    // C9.2: Collection = single select, no operator dropdown, always 'equals'
    // Toggle boolean: no operator dropdown
    const effectiveOperator = isColorFilter || isCollectionFilter || isToggleBoolean ? 'equals' : operator
    const effectiveValue = isColorFilter
        ? (Array.isArray(value) ? value : (value != null ? [value] : null))
        : (isCollectionFilter ? (Array.isArray(value) ? value : (value != null ? [value] : null)) : (isToggleBoolean ? value : value))
    
    // Special handling for dominant_colors / color / collection / toggle boolean - hide operator dropdown, show only value control
    const isDominantColors = (fieldKey === 'dominant_colors')
    
    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-gray-700">
                {field.display_label || field.label}
            </label>
            {/* For dominant_colors, color, collection, or toggle boolean: render value control only (no operator dropdown) */}
            {(isDominantColors || isColorFilter || isCollectionFilter || isToggleBoolean) ? (
                <FilterValueInput
                    field={field}
                    operator={effectiveOperator}
                    value={effectiveValue}
                    filteredOptions={filteredOptions}
                    availableValues={availableValues}
                    onChange={handleValueChange}
                />
            ) : (
                <div className="flex items-center gap-2">
                    {/* STEP 3: Hide operator dropdown for color/collection/toggle boolean filters */}
                    {!isColorFilter && !isCollectionFilter && !isToggleBoolean && field.operators && field.operators.length > 1 && (
                        <select
                            value={effectiveOperator}
                            onChange={handleOperatorChange}
                            className="flex-shrink-0 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            {field.operators.map((op) => (
                                <option key={op.value} value={op.value}>
                                    {op.label}
                                </option>
                            ))}
                        </select>
                    )}
                    <FilterValueInput
                        field={field}
                        operator={effectiveOperator}
                        value={effectiveValue}
                        filteredOptions={filteredOptions}
                        availableValues={availableValues}
                        onChange={handleValueChange}
                    />
                </div>
            )}
        </div>
    )
}

/**
 * Filter Value Input Component
 * 
 * Renders the appropriate input based on field type.
 * Uses filteredOptions to only show options that exist in available_values.
 */
function FilterValueInput({ field, operator, value, onChange, filteredOptions = null, availableValues = [] }) {
    const fieldType = field.type || 'text'
    const fieldKey = field.field_key || field.key
    
    // Boolean with display_widget=toggle (e.g. Starred) — same layout as upload/edit/primary filters
    if (fieldKey === 'starred' || (fieldType === 'boolean' && field.display_widget === 'toggle')) {
        const isOn = value === true || value === 'true'
        return (
            <label className="flex items-center gap-2 cursor-pointer">
                <div className="relative inline-flex items-center flex-shrink-0">
                    <input
                        type="checkbox"
                        checked={!!isOn}
                        onChange={(e) => onChange('equals', e.target.checked ? true : null)}
                        className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600" />
                </div>
                <span className="text-xs text-gray-600">{isOn ? 'Yes' : 'Any'}</span>
            </label>
        )
    }
    
    // C9.2: Collection = single dropdown (not "Contains any" multiselect) in secondary filters too
    if (fieldKey === 'collection') {
        const opts = (filteredOptions !== null && filteredOptions.length > 0)
            ? filteredOptions
            : (field.options || [])
        const label = (opt) => opt.display_label ?? opt.label ?? opt.value
        return (
            <select
                value={Array.isArray(value) ? value[0] ?? '' : (value ?? '')}
                onChange={(e) => {
                    const v = e.target.value
                    onChange(v ? [v] : null)
                }}
                className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            >
                <option value="">Any</option>
                {opts.map((option) => (
                    <option key={option.value} value={option.value}>
                        {label(option)}
                    </option>
                ))}
            </select>
        )
    }
    
    // Special handling for dominant_colors field - render color tiles
    if (fieldKey === 'dominant_colors') {
        // availableValues for dominant_colors will be an array of color objects: [{hex, rgb, coverage}, ...]
        // The backend extracts individual color objects from the multiselect arrays
        // Pass availableValues directly - DominantColorsFilter handles both formats
        return (
            <DominantColorsFilter
                value={value}
                onChange={onChange}
                availableValues={availableValues}
                compact={true}
            />
        )
    }

    // Color swatch filter (e.g. dominant_color_bucket): filter_type === 'color', options have swatch hex
    if (field.filter_type === 'color') {
        return (
            <ColorSwatchFilter
                field={field}
                value={value}
                onChange={onChange}
                filteredOptions={filteredOptions}
                compact={true}
            />
        )
    }
    
    // Use filteredOptions if provided and non-empty, otherwise fall back to field.options
    // filteredOptions is null when not provided (use all options)
    // filteredOptions is [] when filtering resulted in no matches (still use all options as fallback)
    // filteredOptions has items when filtering found matches (use filtered list)
    const options = (filteredOptions !== null && filteredOptions.length > 0) 
        ? filteredOptions 
        : (field.options || [])
    
    switch (fieldType) {
        case 'text':
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter value..."
                />
            )
        
        case 'number':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1 flex-1">
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Min"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Max"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="number"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter number..."
                />
            )
        
        case 'boolean':
            return (
                <select
                    value={value === null ? '' : String(value)}
                    onChange={(e) => onChange(e.target.value === 'true' ? true : e.target.value === 'false' ? false : null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="">Any</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                </select>
            )
        
        case 'select':
            return (
                <select
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="">Any</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label || option.value}
                        </option>
                    ))}
                </select>
            )
        
        case 'multiselect':
            return (
                <select
                    multiple
                    value={Array.isArray(value) ? value : []}
                    onChange={(e) => {
                        const selected = Array.from(e.target.selectedOptions, (opt) => opt.value)
                        onChange(selected.length > 0 ? selected : null)
                    }}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    size={Math.min((options?.length || 0) + 1, 5)}
                >
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label || option.value}
                        </option>
                    ))}
                </select>
            )
        
        case 'date':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1 flex-1">
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="date"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
            )
        
        default:
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter value..."
                />
            )
    }
}
