/**
 * AssetGridToolbar Component
 * 
 * Professional toolbar for asset grid display controls.
 * This component handles UI-only grid presentation settings.
 * 
 * Features:
 * - Search input (coming soon - Phase 6.1)
 * - Primary metadata filters (between search and controls)
 * - "Show Info" toggle (controls asset card metadata visibility)
 * - Grid size controls (card size control)
 * - Bulk selection toggle (if applicable)
 * - More filters section (optional)
 * - Responsive layout (mobile-first)
 * 
 * @param {Object} props
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {Function} props.onToggleInfo - Callback to toggle info visibility
 * @param {number} props.cardSize - Current card size in pixels (160-360, default 220)
 * @param {Function} props.onCardSizeChange - Callback when card size changes
 * @param {'grid'|'masonry'} props.layoutMode - Uniform tiles vs masonry columns
 * @param {Function} props.onLayoutModeChange - (mode) => void
 * @param {string} props.primaryColor - Brand primary color for slider styling
 * @param {Array} props.filterable_schema - Filterable metadata schema from backend
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Object} props.available_values - Map of field_key to available values
 * @param {React.ReactNode} props.moreFiltersContent - Optional more filters section content
 * @param {boolean} props.showMoreFilters - Whether to show the more filters section
 * @param {React.ReactNode} props.beforeSearchSlot - Optional controls rendered before the search field (e.g. collections type/category)
 */
import { useState, useEffect, useRef, useCallback, useMemo, cloneElement, isValidElement } from 'react'
import { usePage, router } from '@inertiajs/react'
import AssetGridMetadataPrimaryFilters from './AssetGridMetadataPrimaryFilters'
import AssetGridSearchInput from './AssetGridSearchInput'
import { InformationCircleIcon, ClockIcon, TagIcon, XMarkIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import SortDropdown from './SortDropdown'
import { usePermission } from '../hooks/usePermission'
import { updateFilterDebug } from '../utils/assetFilterDebug'
import MoreFiltersTriggerButton from './MoreFiltersTriggerButton'
import { clearToolbarFilterParams, toolbarQueryHasClearableFilters } from '../utils/filterUrlUtils'

export default function AssetGridToolbar({
    showInfo = true,
    onToggleInfo = () => {},
    cardSize = 220,
    onCardSizeChange = () => {},
    layoutMode = 'grid',
    onLayoutModeChange = () => {},
    primaryColor = '#6366f1', // Default indigo-600
    selectedCount = 0, // SelectionContext count (replaces bucketCount)
    filterable_schema = [], // Primary metadata filters
    selectedCategoryId = null, // Current category
    available_values = {}, // Available filter values
    moreFiltersContent = null, // More filters section content
    showMoreFilters = false, // Whether to show more filters section
    beforeSearchSlot = null,
    sortBy = 'created', // used when showMoreFilters is false (e.g. Collections)
    sortDirection = 'desc',
    onSortChange = null,
    searchQuery = '', // ?q= from server; syncs to URL on debounced change
    /** Partial reload keys for search (e.g. Collections adds group_by_category, filters). */
    inertiaSearchOnly = null,
    /** When set (e.g. active tenant id), search shows tag autocomplete from `/app/api/tenants/{id}/tags/autocomplete`. */
    searchTagAutocompleteTenantId = null,
    /** Override default search placeholder (e.g. mention tags on Collections). */
    searchPlaceholder = null,
    /** Deliverables: compliance row inside Sort dropdown. */
    showComplianceFilter = false,
    /** Clear-all also resets collection type/category filters (Collections view). */
    clearFiltersCollectionsView = false,
    /** Inertia `only` keys for clear-all navigation (must match each page’s partial reload). */
    clearFiltersInertiaOnly = null,
}) {
    const inertiaPage = usePage()
    const pageProps = inertiaPage.props
    const pageUrl = inertiaPage.url
    const { auth } = pageProps
    const brand = auth?.activeBrand
    const serverQ = (typeof pageProps.q === 'string' ? pageProps.q : searchQuery) || ''

    // Search loading: in-flight request feedback (no layout shift, typing uninterrupted)
    const [searchLoading, setSearchLoading] = useState(false)
    const searchInputRef = useRef(null)
    const searchHadFocusRef = useRef(false)
    const [mobileSearchOpen, setMobileSearchOpen] = useState(false)
    const mobileSearchInputRef = useRef(null)

    const applySearch = useCallback((trimmed, hadFocus = false) => {
        searchHadFocusRef.current = hadFocus
        const urlParams = new URLSearchParams(window.location.search)
        if (trimmed) {
            urlParams.set('q', trimmed)
        } else {
            urlParams.delete('q')
        }
        const onlyKeys = Array.isArray(inertiaSearchOnly) && inertiaSearchOnly.length > 0
            ? inertiaSearchOnly
            : ['assets', 'next_page_url', 'q', 'filtered_grid_total', 'grid_folder_total']
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: onlyKeys,
            onStart: () => setSearchLoading(true),
            onFinish: () => {
                setSearchLoading(false)
                if (searchHadFocusRef.current) {
                    searchHadFocusRef.current = false
                    const el = searchInputRef.current
                    const doFocus = () => {
                        if (el?.focus) el.focus()
                    }
                    requestAnimationFrame(() => {
                        requestAnimationFrame(doFocus)
                    })
                }
            },
        })
    }, [inertiaSearchOnly])

    const applySearchAndCloseMobile = useCallback(
        (trimmed, hadFocus = false) => {
            applySearch(trimmed, hadFocus)
            setMobileSearchOpen(false)
        },
        [applySearch]
    )

    const [toolbarMoreExpanded, setToolbarMoreExpanded] = useState(false)

    useEffect(() => {
        if (!mobileSearchOpen) return
        const onKey = (e) => {
            if (e.key === 'Escape') setMobileSearchOpen(false)
        }
        window.addEventListener('keydown', onKey)
        return () => window.removeEventListener('keydown', onKey)
    }, [mobileSearchOpen])

    useEffect(() => {
        if (!mobileSearchOpen) return
        const id = requestAnimationFrame(() => {
            mobileSearchInputRef.current?.focus?.()
        })
        return () => cancelAnimationFrame(id)
    }, [mobileSearchOpen])
    const [moreFiltersBarMeta, setMoreFiltersBarMeta] = useState(() => ({
        activeFilterCount: 0,
        visibleSecondaryFiltersLength: 0,
        brandPrimary: primaryColor,
        desktopResultSummary: '',
    }))

    const reportMoreFiltersMeta = useCallback((meta) => {
        setMoreFiltersBarMeta((prev) => {
            if (
                prev &&
                prev.activeFilterCount === meta.activeFilterCount &&
                prev.visibleSecondaryFiltersLength === meta.visibleSecondaryFiltersLength &&
                prev.brandPrimary === meta.brandPrimary &&
                prev.desktopResultSummary === (meta.desktopResultSummary ?? '')
            ) {
                return prev
            }
            return { ...meta, desktopResultSummary: meta.desktopResultSummary ?? '' }
        })
    }, [])

    useEffect(() => {
        setMoreFiltersBarMeta((prev) => (prev ? { ...prev, brandPrimary: primaryColor } : prev))
    }, [primaryColor])

    const renderedMoreFilters = useMemo(() => {
        if (!showMoreFilters || !moreFiltersContent) return null
        if (!isValidElement(moreFiltersContent)) return moreFiltersContent
        return cloneElement(moreFiltersContent, {
            toolbarMoreFiltersExpanded: toolbarMoreExpanded,
            onToolbarMoreFiltersExpandedChange: setToolbarMoreExpanded,
            hideInlineMoreFiltersButton: true,
            onToolbarMoreFiltersMetaReport: reportMoreFiltersMeta,
            hideSortInSecondaryBar: true,
        })
    }, [showMoreFilters, moreFiltersContent, toolbarMoreExpanded, reportMoreFiltersMeta])

    const filterKeysForClear = useMemo(
        () => (filterable_schema || []).map((f) => f.field_key || f.key).filter(Boolean),
        [filterable_schema]
    )

    const showClearAllFilters = useMemo(() => {
        const searchPart = pageUrl.includes('?') ? `?${pageUrl.split('?')[1]}` : ''
        return toolbarQueryHasClearableFilters(searchPart, filterKeysForClear, clearFiltersCollectionsView)
    }, [pageUrl, serverQ, filterKeysForClear, clearFiltersCollectionsView])

    const defaultClearOnly = useMemo(
        () => ['assets', 'next_page_url', 'filters', 'uploaded_by_users', 'q', 'filtered_grid_total', 'grid_folder_total'],
        []
    )

    const handleClearAllFilters = useCallback(() => {
        const next = clearToolbarFilterParams(window.location.search, {
            filterKeys: filterKeysForClear,
            collectionsView: clearFiltersCollectionsView,
        })
        const only = clearFiltersInertiaOnly ?? defaultClearOnly
        setToolbarMoreExpanded(false)
        router.get(window.location.pathname, Object.fromEntries(next), {
            preserveState: true,
            preserveScroll: true,
            only,
        })
    }, [filterKeysForClear, clearFiltersCollectionsView, clearFiltersInertiaOnly, defaultClearOnly])

    // Pending assets callout state
    const [pendingAssetsCount, setPendingAssetsCount] = useState(0)
    const [pendingTagsCount, setPendingTagsCount] = useState(0)
    const [loadingPendingCounts, setLoadingPendingCounts] = useState(false)
    
    // Check if user can approve assets (backend owns permission logic)
    const { can } = usePermission()
    const canApprove = can('metadata.bypass_approval')
    const approvalsEnabled = auth?.approval_features?.approvals_enabled
    
    useEffect(() => {
        updateFilterDebug({ pendingAssets: { categoryId: selectedCategoryId, loading: true } })
        if (!canApprove || !approvalsEnabled || !brand?.id || !selectedCategoryId) {
            setPendingAssetsCount(0)
            setPendingTagsCount(0)
            updateFilterDebug({ pendingAssets: { categoryId: selectedCategoryId, count: 0, loading: false } })
            return
        }
        setLoadingPendingCounts(true)
        const categoryId = typeof selectedCategoryId === 'string' ? parseInt(selectedCategoryId, 10) : selectedCategoryId
        const url = `/app/api/brands/${brand.id}/pending-assets?category_id=${categoryId}`
        fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                if (!res.ok) {
                    const errorText = await res.text()
                    console.error('[AssetGridToolbar] API error response:', res.status, errorText)
                    throw new Error(`HTTP ${res.status}: ${errorText}`)
                }
                return res.json()
            })
            .then((data) => {
                const count = data.count || data.assets?.length || 0
                setPendingAssetsCount(count)
                setLoadingPendingCounts(false)
                updateFilterDebug({ pendingAssets: { categoryId: selectedCategoryId, count, loading: false } })
            })
            .catch((err) => {
                setPendingAssetsCount(0)
                setLoadingPendingCounts(false)
                updateFilterDebug({ pendingAssets: { categoryId: selectedCategoryId, count: 0, loading: false, error: String(err?.message || err) } })
            })
        
        // TODO: Fetch pending tag suggestions count for this category
        // For now, set to 0
        setPendingTagsCount(0)
    }, [canApprove, approvalsEnabled, brand?.id, selectedCategoryId])
    
    // Handle click on pending assets callout - enable 'Pending Publication' filter
    const handlePendingAssetsClick = () => {
        const currentUrl = new URL(window.location.href)
        currentUrl.searchParams.set('lifecycle', 'pending_publication')
        router.visit(currentUrl.toString(), {
            preserveState: false,
            preserveScroll: false,
        })
    }
    
    // Grid size button group - 4 discrete settings (compact to spacious)
    const SIZE_PRESETS = [160, 220, 280, 360]
    
    // Snap cardSize to nearest preset
    const snapToPreset = (value) => {
        return SIZE_PRESETS.reduce((prev, curr) => 
            Math.abs(curr - value) < Math.abs(prev - value) ? curr : prev
        )
    }
    
    // Get current preset index (0-3)
    const currentPresetIndex = SIZE_PRESETS.indexOf(snapToPreset(cardSize))
    
    // Grid size icons - representing different grid densities
    const SizeIcon = ({ size, className = "h-4 w-4" }) => {
        // Different grid patterns for each size
        const gridPatterns = {
            small: (
                <svg className={className} fill="none" viewBox="0 0 28 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="4" height="6" rx="0.5" />
                    <rect x="6" y="1" width="4" height="6" rx="0.5" />
                    <rect x="11" y="1" width="4" height="6" rx="0.5" />
                    <rect x="16" y="1" width="4" height="6" rx="0.5" />
                    <rect x="21" y="1" width="4" height="6" rx="0.5" />
                    <rect x="1" y="9" width="4" height="6" rx="0.5" />
                    <rect x="6" y="9" width="4" height="6" rx="0.5" />
                    <rect x="11" y="9" width="4" height="6" rx="0.5" />
                    <rect x="16" y="9" width="4" height="6" rx="0.5" />
                    <rect x="21" y="9" width="4" height="6" rx="0.5" />
                </svg>                
            ),
            medium: (
                <svg className={className} fill="none" viewBox="0 0 24 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="6.5" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="12" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="17.5" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="1" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="6.5" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="12" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="17.5" y="9" width="4.5" height="6" rx="0.5" />
                </svg>
            ),
            large: (
                <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="5" height="6" rx="0.5" />
                    <rect x="7.5" y="1" width="5" height="6" rx="0.5" />
                    <rect x="14" y="1" width="5" height="6" rx="0.5" />
                    <rect x="1" y="9" width="5" height="6" rx="0.5" />
                    <rect x="7.5" y="9" width="5" height="6" rx="0.5" />
                    <rect x="14" y="9" width="5" height="6" rx="0.5" />
                </svg>
                
            ),
            xlarge: (
                <svg className={className} fill="none" viewBox="0 0 16 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="6" height="6" rx="0.5" />
                    <rect x="9" y="1" width="6" height="6" rx="0.5" />
                    <rect x="1" y="9" width="6" height="6" rx="0.5" />
                    <rect x="9" y="9" width="6" height="6" rx="0.5" />
                </svg>                
            ),
        }
        
        return gridPatterns[size] || gridPatterns.medium
    }

    /** Uniform grid vs masonry — icon-only radios beside tile size */
    const LayoutUniformIcon = ({ className = 'h-4 w-4' }) => (
        <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
            <rect x="1" y="1" width="5" height="6" rx="0.5" />
            <rect x="7.5" y="1" width="5" height="6" rx="0.5" />
            <rect x="14" y="1" width="5" height="6" rx="0.5" />
            <rect x="1" y="9" width="5" height="6" rx="0.5" />
            <rect x="7.5" y="9" width="5" height="6" rx="0.5" />
            <rect x="14" y="9" width="5" height="6" rx="0.5" />
        </svg>
    )
    const LayoutMasonryIcon = ({ className = 'h-4 w-4' }) => (
        <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
            <rect x="1" y="1" width="5.5" height="4" rx="0.5" />
            <rect x="8" y="1" width="5.5" height="7" rx="0.5" />
            <rect x="14.5" y="1" width="4.5" height="5" rx="0.5" />
            <rect x="1" y="6" width="5.5" height="9" rx="0.5" />
            <rect x="8" y="9" width="5.5" height="6" rx="0.5" />
            <rect x="14.5" y="7" width="4.5" height="8" rx="0.5" />
        </svg>
    )

    return (
        <div className={`bg-white ${showMoreFilters ? 'border-b border-gray-200' : 'border-b border-gray-200'}`}>
            {/* Pending Assets Callout - Above search bar */}
            {canApprove && approvalsEnabled && selectedCategoryId && (pendingAssetsCount > 0 || pendingTagsCount > 0) && (
                <div className="px-3 pt-2 pb-1.5 sm:px-4">
                    <div className="flex items-center gap-2 flex-wrap">
                        {pendingAssetsCount > 0 && (
                            <button
                                type="button"
                                onClick={handlePendingAssetsClick}
                                className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-md hover:bg-amber-100 transition-colors"
                            >
                                <ClockIcon className="h-3.5 w-3.5" />
                                <span>{pendingAssetsCount} {pendingAssetsCount === 1 ? 'asset' : 'assets'} for review</span>
                            </button>
                        )}
                        {pendingTagsCount > 0 && (
                            <button
                                type="button"
                                className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-blue-700 bg-blue-50 border border-blue-200 rounded-md hover:bg-blue-100 transition-colors"
                            >
                                <TagIcon className="h-3.5 w-3.5" />
                                <span>{pendingTagsCount} {pendingTagsCount === 1 ? 'tag' : 'tags'} suggested</span>
                            </button>
                        )}
                    </div>
                </div>
            )}
            
            {/* Primary Toolbar Row — lg+: inline search; mobile: search icon opens sheet; one compact control line */}
            <div className="px-3 py-2 sm:py-2.5 sm:px-4">
                <div className="flex flex-row flex-nowrap items-center gap-1.5 min-w-0 justify-between lg:gap-2">
                    {/* Left: desktop search | mobile search button + More filters strip */}
                    <div className="flex min-w-0 flex-1 items-center gap-1.5 lg:gap-2">
                        <div className="hidden min-h-0 w-0 min-w-[7rem] max-w-xl flex-1 shrink lg:block">
                            <AssetGridSearchInput
                                key="asset-grid-search"
                                serverQuery={serverQ}
                                onSearchApply={applySearch}
                                isSearchPending={searchLoading}
                                placeholder={searchPlaceholder ?? 'Search items…'}
                                inputClassName="py-1.5 text-xs sm:text-sm"
                                inputRef={searchInputRef}
                                tagAutocompleteTenantId={searchTagAutocompleteTenantId}
                                primaryColor={primaryColor}
                            />
                        </div>
                        <button
                            type="button"
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 lg:hidden"
                            style={{ '--tw-ring-color': primaryColor }}
                            aria-label="Open search"
                            title="Search"
                            onClick={() => setMobileSearchOpen(true)}
                        >
                            <MagnifyingGlassIcon className="h-5 w-5 shrink-0" />
                        </button>
                        {/* Scroll only: slot, primary (desktop), More — Sort + Clear stay outside so dropdowns are not clipped */}
                        <div className="flex min-h-0 min-w-0 flex-1 items-center gap-1.5 overflow-x-auto overflow-y-visible py-1 pr-0.5 -my-1 lg:gap-2 lg:py-1.5">
                            {beforeSearchSlot ? (
                                <div className="flex max-w-[min(100%,42rem)] shrink-0 flex-nowrap items-center gap-2">
                                    {beforeSearchSlot}
                                </div>
                            ) : null}
                            <div className="hidden min-w-0 shrink-0 lg:flex items-center gap-2">
                                <AssetGridMetadataPrimaryFilters
                                    filterable_schema={filterable_schema}
                                    selectedCategoryId={selectedCategoryId}
                                    available_values={available_values}
                                    assetType="image"
                                    compact={true}
                                    primaryColor={primaryColor}
                                />
                            </div>
                            {showMoreFilters && isValidElement(moreFiltersContent) && (
                                <div className="flex shrink-0">
                                    <MoreFiltersTriggerButton
                                        isExpanded={toolbarMoreExpanded}
                                        onToggle={() => setToolbarMoreExpanded((v) => !v)}
                                        activeFilterCount={moreFiltersBarMeta.activeFilterCount}
                                        brandPrimary={moreFiltersBarMeta.brandPrimary}
                                        visibleSecondaryFiltersLength={moreFiltersBarMeta.visibleSecondaryFiltersLength}
                                    />
                                </div>
                            )}
                        </div>
                        {onSortChange && (
                            <div className="flex shrink-0 items-center max-lg:ml-0.5">
                                <SortDropdown
                                    sortBy={sortBy}
                                    sortDirection={sortDirection}
                                    onSortChange={onSortChange}
                                    showComplianceFilter={showComplianceFilter}
                                    primaryColor={primaryColor}
                                />
                            </div>
                        )}
                        {showClearAllFilters && (
                            <button
                                type="button"
                                onClick={handleClearAllFilters}
                                className="flex shrink-0 items-center gap-0.5 rounded-md border border-slate-200 bg-white px-1.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-600 shadow-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400/90 sm:px-2 sm:py-1.5 sm:text-xs sm:normal-case sm:tracking-normal"
                                title="Clear search and all filters"
                                aria-label="Clear search and all filters"
                            >
                                <XMarkIcon className="h-3.5 w-3.5 sm:h-4 sm:w-4" aria-hidden />
                                <span className="hidden sm:inline">Clear</span>
                            </button>
                        )}
                        {moreFiltersBarMeta.desktopResultSummary ? (
                            <span
                                className="hidden shrink-0 whitespace-nowrap text-xs text-gray-500 lg:inline"
                                aria-live="polite"
                            >
                                {moreFiltersBarMeta.desktopResultSummary}
                            </span>
                        ) : null}
                    </div>

                    {/* Right: Info + Grid — compact, flex-shrink-0 */}
                    <div className="flex flex-shrink-0 items-center gap-2 lg:justify-end">
                        {/* Show Info Toggle */}
                        <label className="flex items-center gap-1.5 sm:gap-2 cursor-pointer">                        
                            <InformationCircleIcon className="h-3.5 w-3.5 sm:h-4 sm:w-4 text-gray-700 flex-shrink-0" title="Show info" />

                            <button
                                type="button"
                                role="switch"
                                aria-checked={showInfo}
                                onClick={onToggleInfo}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2`}
                                style={{
                                    backgroundColor: showInfo ? primaryColor : '#d1d5db',
                                }}
                                onFocus={(e) => {
                                    e.currentTarget.style.setProperty('--tw-ring-color', primaryColor)
                                }}
                            >
                                <span
                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform duration-200 ease-in-out ${
                                        showInfo ? 'translate-x-5' : 'translate-x-0'
                                    }`}
                                />
                            </button>                        
                        </label>

                        {/* Grid Size Button Group - 4 on desktop, last 2 only on mobile */}
                        <div className="flex items-center gap-1.5">                        
                            <div className="inline-flex rounded-md shadow-sm" role="group" aria-label="Grid size">
                                {SIZE_PRESETS.map((size, index) => {
                                    const isSelected = currentPresetIndex === index
                                    const iconSizes = ['small', 'medium', 'large', 'xlarge']
                                    const iconSize = iconSizes[index] || 'medium'
                                    // On mobile (default): only show first 2 buttons (small, medium). On md+: show all 4.
                                    const isMobileOnlyHidden = index >= 2
                                    
                                    return (
                                        <button
                                            key={size}
                                            type="button"
                                            onClick={() => onCardSizeChange(size)}
                                            className={`
                                                px-2 py-1 sm:px-2.5 sm:py-1.5 text-xs sm:text-sm font-medium transition-all
                                                flex items-center justify-center
                                                ${index === 0 ? 'rounded-l-md' : ''}
                                                ${index === SIZE_PRESETS.length - 1 ? 'rounded-r-md' : ''}
                                                ${index === 1 ? 'rounded-r-md md:rounded-r-none' : ''}
                                                ${index > 0 ? '-ml-px' : ''}
                                                ${isSelected 
                                                    ? 'bg-white text-gray-900 shadow-sm z-10' 
                                                    : 'bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-700'
                                                }
                                                border border-gray-300
                                                focus:z-10 focus:outline-none focus:ring-2 focus:ring-offset-0
                                                ${isMobileOnlyHidden ? 'hidden md:flex' : ''}
                                            `}
                                            style={isSelected ? {
                                                borderColor: primaryColor,
                                                '--tw-ring-color': primaryColor,
                                            } : {}}
                                            aria-pressed={isSelected}
                                            aria-label={`${iconSize} tile size`}
                                            title={`${iconSize.charAt(0).toUpperCase() + iconSize.slice(1)} tile size`}
                                        >
                                            <SizeIcon size={iconSize} className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                        </button>
                                    )
                                })}
                            </div>

                            <div
                                className="inline-flex rounded-md shadow-sm"
                                role="radiogroup"
                                aria-label="Asset layout"
                            >
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={layoutMode === 'grid'}
                                    onClick={() => onLayoutModeChange('grid')}
                                    className={`
                                        px-2 py-1 sm:px-2.5 sm:py-1.5 text-xs sm:text-sm font-medium transition-all
                                        flex items-center justify-center rounded-l-md border border-gray-300
                                        focus:z-10 focus:outline-none focus:ring-2 focus:ring-offset-0
                                        ${layoutMode === 'grid'
                                            ? 'bg-white text-gray-900 shadow-sm z-10'
                                            : 'bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-700'
                                        }
                                    `}
                                    style={layoutMode === 'grid' ? { borderColor: primaryColor, '--tw-ring-color': primaryColor } : {}}
                                    title="Uniform grid"
                                >
                                    <LayoutUniformIcon className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                    <span className="sr-only">Uniform grid</span>
                                </button>
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={layoutMode === 'masonry'}
                                    onClick={() => onLayoutModeChange('masonry')}
                                    className={`
                                        -ml-px px-2 py-1 sm:px-2.5 sm:py-1.5 text-xs sm:text-sm font-medium transition-all
                                        flex items-center justify-center rounded-r-md border border-gray-300
                                        focus:z-10 focus:outline-none focus:ring-2 focus:ring-offset-0
                                        ${layoutMode === 'masonry'
                                            ? 'bg-white text-gray-900 shadow-sm z-10'
                                            : 'bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-700'
                                        }
                                    `}
                                    style={layoutMode === 'masonry' ? { borderColor: primaryColor, '--tw-ring-color': primaryColor } : {}}
                                    title="Masonry — full image, max height"
                                >
                                    <LayoutMasonryIcon className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                    <span className="sr-only">Masonry</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* More Filters Section — More filters stays far left; selection buttons live in bar right (via barTrailingContent from parent) */}
            {showMoreFilters && renderedMoreFilters && (
                <div className="border-t border-gray-200">
                    {renderedMoreFilters}
                </div>
            )}

            {/* Mobile: full-width search sheet (keeps toolbar to one icon row) */}
            {mobileSearchOpen && (
                <div
                    className="fixed inset-0 z-[260] flex flex-col bg-black/50 lg:hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Search assets"
                >
                    <button
                        type="button"
                        className="min-h-[25%] w-full flex-1 cursor-default"
                        aria-label="Close search"
                        onClick={() => setMobileSearchOpen(false)}
                    />
                    <div className="max-h-[75vh] rounded-t-2xl bg-white p-3 shadow-2xl ring-1 ring-black/5">
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <span className="text-sm font-semibold text-gray-900">Search</span>
                            <button
                                type="button"
                                className="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                                onClick={() => setMobileSearchOpen(false)}
                            >
                                Done
                            </button>
                        </div>
                        <AssetGridSearchInput
                            key="asset-grid-search-mobile"
                            serverQuery={serverQ}
                            onSearchApply={applySearchAndCloseMobile}
                            isSearchPending={searchLoading}
                            placeholder={searchPlaceholder ?? 'Search items…'}
                            inputClassName="py-2.5 text-base"
                            inputRef={mobileSearchInputRef}
                            tagAutocompleteTenantId={searchTagAutocompleteTenantId}
                            primaryColor={primaryColor}
                        />
                    </div>
                </div>
            )}
        </div>
    )
}