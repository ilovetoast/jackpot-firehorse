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
 * - Secondary filters / Filters panel (optional)
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
 * @param {string} [props.primaryMetadataFiltersAssetType='image'] - Inline primary metadata filters: use `deliverable` on Deliverables.
 */
import { useState, useEffect, useRef, useCallback, useMemo, cloneElement, isValidElement } from 'react'
import { usePage, router } from '@inertiajs/react'
import AssetGridMetadataPrimaryFilters from './AssetGridMetadataPrimaryFilters'
import AssetGridSearchInput from './AssetGridSearchInput'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import { ClockIcon, TagIcon, XMarkIcon, MagnifyingGlassIcon, ClipboardDocumentListIcon } from '@heroicons/react/24/outline'
import SortDropdown from './SortDropdown'
import { usePermission } from '../hooks/usePermission'
import { updateFilterDebug } from '../utils/assetFilterDebug'
import MoreFiltersTriggerButton from './MoreFiltersTriggerButton'
import AssetGridViewMenu from './AssetGridViewMenu'
import AssetGridViewOptionsDropdown from './AssetGridViewOptionsDropdown'
import { clearToolbarFilterParams, toolbarQueryHasClearableFilters } from '../utils/filterUrlUtils'

const TOOLBAR_OVERFLOW_DEBOUNCE_MS = 200
const MORE_FILTERS_PANEL_ID = 'asset-grid-more-filters-panel'

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
    /** Preserve these query keys when primary metadata filters rebuild the URL (Collections: `collection`, etc.). */
    filterUrlNavigationKeys = [],
    primaryMetadataFiltersAssetType = 'image',
    /** Deliverables: grid thumbnail mode — 'standard' | 'enhanced' | 'presentation' */
    executionThumbnailViewMode = null,
    onExecutionThumbnailViewModeChange = null,
}) {
    const inertiaPage = usePage()
    const pageProps = inertiaPage.props
    const pageUrl = inertiaPage.url
    const { auth } = pageProps
    const brand = auth?.activeBrand
    // Derive the active search query from the Inertia page URL (source of truth for the
    // browser URL), falling back to the shared `q` prop and the `searchQuery` prop.
    // Inertia partial reloads that omit `q` from `only` can drop `pageProps.q`, which
    // previously caused the search input to clear itself even though the URL still held
    // ?q=… — keeping the input in sync with the URL avoids that stale-sync bug.
    const serverQ = (() => {
        if (typeof pageUrl === 'string' && pageUrl.length > 0) {
            const qIndex = pageUrl.indexOf('?')
            if (qIndex !== -1) {
                try {
                    const params = new URLSearchParams(pageUrl.slice(qIndex + 1))
                    const fromUrl = params.get('q')
                    if (fromUrl != null) return fromUrl
                } catch {
                    // fall through to prop-based fallback
                }
            }
        }
        return (typeof pageProps.q === 'string' ? pageProps.q : searchQuery) || ''
    })()

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
            // Drop deep-link params so Index.jsx recovery effect does not re-apply ?q=uuid while ?asset= remains
            urlParams.delete('asset')
            urlParams.delete('edit_metadata')
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
                // Re-focus only if the input lost focus during the partial reload.
                // Refocusing while it already has focus produces a visible caret blink
                // and, on iOS, can re-open the software keyboard on every request —
                // which users perceive as a page refresh mid-typing.
                if (searchHadFocusRef.current) {
                    searchHadFocusRef.current = false
                    const el = searchInputRef.current
                    if (el && document.activeElement !== el) {
                        requestAnimationFrame(() => {
                            requestAnimationFrame(() => {
                                if (el && document.activeElement !== el && el.focus) {
                                    el.focus()
                                }
                            })
                        })
                    }
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
    const toolbarRowRef = useRef(null)
    /** Search + inline filters row; overflow ⇒ hide primary filters into Filters panel (lg+). */
    const queryClusterRef = useRef(null)
    const collapseInlinePrimaryRef = useRef(false)
    const [collapseInlinePrimaryFilters, setCollapseInlinePrimaryFilters] = useState(false)
    const [displayInPopover, setDisplayInPopover] = useState(false)
    const displayInPopoverRef = useRef(false)
    displayInPopoverRef.current = displayInPopover

    const [viewportReady, setViewportReady] = useState(false)
    const [isLgViewport, setIsLgViewport] = useState(false)
    const [isXlViewport, setIsXlViewport] = useState(false)
    useEffect(() => {
        const mq = window.matchMedia('(min-width: 1024px)')
        const sync = () => setIsLgViewport(mq.matches)
        sync()
        setViewportReady(true)
        mq.addEventListener('change', sync)
        return () => mq.removeEventListener('change', sync)
    }, [])
    useEffect(() => {
        const mq = window.matchMedia('(min-width: 1280px)')
        const sync = () => setIsXlViewport(mq.matches)
        sync()
        mq.addEventListener('change', sync)
        return () => mq.removeEventListener('change', sync)
    }, [])

    useEffect(() => {
        collapseInlinePrimaryRef.current = false
        setCollapseInlinePrimaryFilters(false)
    }, [filterable_schema, selectedCategoryId])

    useEffect(() => {
        const row = toolbarRowRef.current
        if (!row || typeof ResizeObserver === 'undefined') return
        const mq = window.matchMedia('(min-width: 1024px)')
        let debounceId = null

        const measureToolbarRowOverflow = () => {
            if (!mq.matches) {
                setDisplayInPopover(false)
                return
            }
            if (displayInPopoverRef.current) return
            if (row.scrollWidth > row.clientWidth + 4) {
                setDisplayInPopover(true)
            }
        }

        const measureQueryClusterOverflow = () => {
            if (!mq.matches) {
                collapseInlinePrimaryRef.current = false
                setCollapseInlinePrimaryFilters(false)
                return
            }
            if (!showMoreFilters) {
                collapseInlinePrimaryRef.current = false
                setCollapseInlinePrimaryFilters(false)
                return
            }
            const cluster = queryClusterRef.current
            if (!cluster) return
            if (collapseInlinePrimaryRef.current) return
            if (cluster.scrollWidth > cluster.clientWidth + 2) {
                collapseInlinePrimaryRef.current = true
                setCollapseInlinePrimaryFilters(true)
            }
        }

        const runMeasures = () => {
            measureQueryClusterOverflow()
            requestAnimationFrame(() => {
                measureToolbarRowOverflow()
            })
        }

        const ro = new ResizeObserver(() => runMeasures())
        ro.observe(row)
        const clusterEl = queryClusterRef.current
        if (clusterEl) {
            ro.observe(clusterEl)
        }

        const onWinResize = () => {
            clearTimeout(debounceId)
            debounceId = window.setTimeout(() => {
                if (!mq.matches) return
                collapseInlinePrimaryRef.current = false
                setCollapseInlinePrimaryFilters(false)
                setDisplayInPopover(false)
                requestAnimationFrame(() => runMeasures())
            }, TOOLBAR_OVERFLOW_DEBOUNCE_MS)
        }
        window.addEventListener('resize', onWinResize)
        runMeasures()
        return () => {
            ro.disconnect()
            window.removeEventListener('resize', onWinResize)
            clearTimeout(debounceId)
        }
    }, [showMoreFilters])

    useEffect(() => {
        if (!toolbarMoreExpanded || !showMoreFilters || isLgViewport) return
        const prevOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        const onKey = (e) => {
            if (e.key === 'Escape') setToolbarMoreExpanded(false)
        }
        window.addEventListener('keydown', onKey)
        return () => {
            document.body.style.overflow = prevOverflow
            window.removeEventListener('keydown', onKey)
        }
    }, [toolbarMoreExpanded, showMoreFilters, isLgViewport])

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
    
    const viewOptionsProps = {
        cardSize,
        onCardSizeChange,
        layoutMode,
        onLayoutModeChange,
        showInfo,
        onToggleInfo,
        primaryColor,
        executionThumbnailViewMode,
        onExecutionThumbnailViewModeChange,
    }

    const mobileResultPanelClass =
        'z-[210] w-[min(calc(100vw-1.5rem),16rem)] [--anchor-gap:6px] rounded-xl border border-gray-200 bg-white/95 p-3 shadow-2xl ring-1 ring-black/5 backdrop-blur-md motion-safe:transition motion-safe:duration-200 motion-safe:ease-out data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100'

    const mobileResultSummaryPopover =
        moreFiltersBarMeta.desktopResultSummary ? (
            <Popover className="relative shrink-0 lg:hidden">
                <PopoverButton
                    type="button"
                    className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset motion-safe:transition-colors motion-reduce:transition-none"
                    style={{ '--tw-ring-color': primaryColor }}
                    aria-label="Results summary"
                    title="Results summary"
                >
                    <ClipboardDocumentListIcon className="h-4 w-4 shrink-0" aria-hidden />
                </PopoverButton>
                <PopoverPanel transition anchor="bottom end" className={mobileResultPanelClass}>
                    <p className="text-sm leading-snug text-gray-700">{moreFiltersBarMeta.desktopResultSummary}</p>
                </PopoverPanel>
            </Popover>
        ) : null

    const clearFiltersButton = showClearAllFilters ? (
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
    ) : null

    const resultSummaryEl = moreFiltersBarMeta.desktopResultSummary ? (
        <span className="shrink-0 whitespace-nowrap text-xs text-gray-500" aria-live="polite">
            {moreFiltersBarMeta.desktopResultSummary}
        </span>
    ) : null

    const sortDesktopVariant = displayInPopover ? 'pill' : isXlViewport ? 'pill' : 'compact'

    const sortDesktopControl = onSortChange ? (
        <SortDropdown
            sortBy={sortBy}
            sortDirection={sortDirection}
            onSortChange={onSortChange}
            showComplianceFilter={showComplianceFilter}
            primaryColor={primaryColor}
            variant={sortDesktopVariant}
            className={
                sortDesktopVariant === 'pill'
                    ? 'max-w-[min(100%,11rem)] min-w-0 shrink'
                    : 'shrink-0'
            }
        />
    ) : null

    const sortPopoverControl = onSortChange ? (
        <SortDropdown
            sortBy={sortBy}
            sortDirection={sortDirection}
            onSortChange={onSortChange}
            showComplianceFilter={showComplianceFilter}
            primaryColor={primaryColor}
            variant="pill"
            className="max-w-full min-w-0"
        />
    ) : null

    const viewPopoverSections = (
        <>
            {onSortChange ? (
                <div className="flex flex-col gap-2 border-b border-gray-100 pb-3">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Sort</p>
                    {sortPopoverControl}
                </div>
            ) : null}
            <div className="flex flex-col gap-2 rounded-lg bg-gray-50/80 px-2 py-2">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">View</p>
                <AssetGridViewOptionsDropdown mode="panel" {...viewOptionsProps} />
            </div>
        </>
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
            
            {/* Primary toolbar: Query | Results | Display; mobile keeps Sort + View on the bar; Filters sheet is metadata filters only */}
            <div className="px-3 py-2 sm:py-2.5 sm:px-4">
                <div
                    ref={toolbarRowRef}
                    className="flex flex-row flex-nowrap items-center gap-1.5 min-w-0 justify-between lg:gap-3"
                >
                    {/* Query cluster — scrollWidth vs clientWidth detects overflow; primary filters then move into Filters panel */}
                    <div
                        ref={queryClusterRef}
                        className="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-visible lg:gap-2 lg:rounded-xl lg:border lg:border-gray-100 lg:bg-gray-50/80 lg:px-2 lg:py-1"
                    >
                        {/* flex-1 + basis-0: search shrinks when the row is tight; filter cluster uses shrink-0 so TYPE/category stay full width */}
                        <div className="hidden min-h-0 min-w-[7rem] max-w-full flex-1 basis-0 lg:block [&_.asset-grid-search-root]:min-w-0">
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
                        <div
                            className="hidden h-6 w-px shrink-0 bg-gray-200 lg:block"
                            aria-hidden
                        />
                        <div className="flex min-h-0 shrink-0 flex-nowrap items-center gap-1.5 overflow-x-visible overflow-y-visible py-1 pr-0.5 -my-1 lg:gap-2 lg:py-1.5">
                            {beforeSearchSlot ? (
                                <div className="flex shrink-0 flex-nowrap items-center gap-2">
                                    {beforeSearchSlot}
                                </div>
                            ) : null}
                            {!collapseInlinePrimaryFilters && (
                                <div className="hidden min-w-0 shrink-0 items-center gap-2 lg:flex">
                                    <AssetGridMetadataPrimaryFilters
                                        filterable_schema={filterable_schema}
                                        selectedCategoryId={selectedCategoryId}
                                        available_values={available_values}
                                        assetType={primaryMetadataFiltersAssetType}
                                        compact={true}
                                        primaryColor={primaryColor}
                                        filterUrlNavigationKeys={filterUrlNavigationKeys}
                                    />
                                </div>
                            )}
                            {showMoreFilters && isValidElement(moreFiltersContent) && (
                                <div className="flex shrink-0">
                                    <MoreFiltersTriggerButton
                                        isExpanded={toolbarMoreExpanded}
                                        onToggle={() => setToolbarMoreExpanded((v) => !v)}
                                        activeFilterCount={moreFiltersBarMeta.activeFilterCount}
                                        brandPrimary={moreFiltersBarMeta.brandPrimary}
                                        visibleSecondaryFiltersLength={moreFiltersBarMeta.visibleSecondaryFiltersLength}
                                        inlinePrimaryFiltersCollapsed={collapseInlinePrimaryFilters}
                                        controlsId={MORE_FILTERS_PANEL_ID}
                                    />
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Mobile: sort, results summary, clear, view — always on the bar (Filters sheet = selectable filters only) */}
                    {onSortChange ? (
                        <div className="flex shrink-0 items-center lg:hidden">
                            <SortDropdown
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSortChange={onSortChange}
                                showComplianceFilter={showComplianceFilter}
                                primaryColor={primaryColor}
                                variant="icon"
                            />
                        </div>
                    ) : null}
                    {mobileResultSummaryPopover}
                    {clearFiltersButton ? (
                        <div className="shrink-0 lg:hidden">{clearFiltersButton}</div>
                    ) : null}
                    <div className="flex shrink-0 items-center lg:hidden">
                        <AssetGridViewOptionsDropdown mode="full" triggerVariant="icon" {...viewOptionsProps} />
                    </div>

                    {/* Desktop: results + display */}
                    <div className="hidden shrink-0 items-center gap-3 lg:flex">
                        {(resultSummaryEl || clearFiltersButton) && (
                            <>
                                <div className="h-6 w-px shrink-0 bg-gray-200" aria-hidden />
                                <div className="flex shrink-0 items-center gap-2 rounded-xl border border-gray-100 bg-gray-50/80 px-2 py-1">
                                    {resultSummaryEl}
                                    {clearFiltersButton}
                                </div>
                            </>
                        )}
                        <div className="h-6 w-px shrink-0 bg-gray-200" aria-hidden />
                        {displayInPopover ? (
                            <AssetGridViewMenu primaryColor={primaryColor}>{viewPopoverSections}</AssetGridViewMenu>
                        ) : (
                            <div className="flex min-w-0 shrink-0 flex-wrap items-center gap-2">
                                <div className="flex min-w-0 shrink-0 items-center gap-2 rounded-xl border border-gray-100 bg-gray-50/80 px-2 py-1">
                                    {sortDesktopControl}
                                    <AssetGridViewOptionsDropdown mode="full" triggerVariant="default" {...viewOptionsProps} />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Secondary filters: desktop inline expand; mobile mounts sheet OR inline (never both) */}
            {showMoreFilters && renderedMoreFilters && (
                <div id={MORE_FILTERS_PANEL_ID}>
                    {viewportReady && toolbarMoreExpanded && !isLgViewport ? (
                        <div
                            className="fixed inset-0 z-[250] flex flex-col justify-end lg:hidden"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="asset-grid-mobile-filters-title"
                        >
                            <button
                                type="button"
                                className="min-h-[20vh] w-full flex-1 cursor-default bg-black/40 motion-safe:transition-opacity motion-safe:duration-200 motion-reduce:transition-none"
                                aria-label="Close filters"
                                onClick={() => setToolbarMoreExpanded(false)}
                            />
                            <div
                                className="relative z-[1] flex max-h-[min(90vh,800px)] w-full flex-col rounded-t-2xl border border-gray-100 bg-white/95 shadow-2xl ring-1 ring-black/5 backdrop-blur-md motion-safe:transition-transform motion-safe:duration-200 motion-reduce:transition-none"
                                style={{ transitionTimingFunction: 'cubic-bezier(0.16, 1, 0.3, 1)' }}
                            >
                                <div className="relative z-20 flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 bg-white/95 px-4 py-3">
                                    <div className="min-w-0">
                                        <p
                                            id="asset-grid-mobile-filters-title"
                                            className="text-sm font-semibold text-gray-900"
                                        >
                                            Filters
                                        </p>
                                        {moreFiltersBarMeta.desktopResultSummary ? (
                                            <p className="truncate text-xs text-gray-500">
                                                {moreFiltersBarMeta.desktopResultSummary}
                                            </p>
                                        ) : null}
                                    </div>
                                    <button
                                        type="button"
                                        className="rounded-full px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 active:scale-95 motion-safe:transition-transform motion-safe:duration-200 motion-reduce:transition-none motion-reduce:active:scale-100"
                                        onClick={() => setToolbarMoreExpanded(false)}
                                    >
                                        Done
                                    </button>
                                </div>
                                <div className="relative z-0 min-h-0 flex-1 overflow-y-auto overscroll-contain">
                                    {renderedMoreFilters}
                                </div>
                            </div>
                        </div>
                    ) : null}
                    {!viewportReady || !toolbarMoreExpanded || isLgViewport ? (
                        <div className="border-t border-gray-200">{renderedMoreFilters}</div>
                    ) : null}
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