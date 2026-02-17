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
 * @param {string} props.primaryColor - Brand primary color for slider styling
 * @param {Array} props.filterable_schema - Filterable metadata schema from backend
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Object} props.available_values - Map of field_key to available values
 * @param {React.ReactNode} props.moreFiltersContent - Optional more filters section content
 * @param {boolean} props.showMoreFilters - Whether to show the more filters section
 */
import { useState, useEffect, useRef, useCallback } from 'react'
import { usePage, router } from '@inertiajs/react'
import AssetGridMetadataPrimaryFilters from './AssetGridMetadataPrimaryFilters'
import AssetGridSearchInput from './AssetGridSearchInput'
import { InformationCircleIcon, ClockIcon, TagIcon, ChevronUpIcon, ChevronDownIcon, BarsArrowDownIcon, BarsArrowUpIcon, SwatchIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import { updateFilterDebug } from '../utils/assetFilterDebug'

export default function AssetGridToolbar({
    showInfo = true,
    onToggleInfo = () => {},
    cardSize = 220,
    onCardSizeChange = () => {},
    cardStyle = 'default',
    onCardStyleChange = null,
    primaryColor = '#6366f1', // Default indigo-600
    bulkSelectedCount = 0, // Phase 2 – Step 7
    onBulkEdit = null, // Phase 2 – Step 7
    onToggleBulkMode = null, // Phase 2 – Step 7
    isBulkMode = false, // Phase 2 – Step 7
    onSelectAllForDownload = null, // Phase D1: Select all for download bucket
    bucketCount = 0, // Phase D1: Download bucket count
    showSelectAllForDownload = false, // Phase D1: Show "Select all" when not in bulk mode
    filterable_schema = [], // Primary metadata filters
    selectedCategoryId = null, // Current category
    available_values = {}, // Available filter values
    moreFiltersContent = null, // More filters section content
    showMoreFilters = false, // Whether to show more filters section
    sortBy = 'created', // used when showMoreFilters is false (e.g. Collections)
    sortDirection = 'desc',
    onSortChange = null,
    searchQuery = '', // ?q= from server; syncs to URL on debounced change
}) {
    const pageProps = usePage().props
    const { auth } = pageProps
    const brand = auth?.activeBrand
    const serverQ = (typeof pageProps.q === 'string' ? pageProps.q : searchQuery) || ''

    // Search loading: in-flight request feedback (no layout shift, typing uninterrupted)
    const [searchLoading, setSearchLoading] = useState(false)
    const searchInputRef = useRef(null)
    const searchHadFocusRef = useRef(false)

    const applySearch = useCallback((trimmed, hadFocus = false) => {
        searchHadFocusRef.current = hadFocus
        const urlParams = new URLSearchParams(window.location.search)
        if (trimmed) {
            urlParams.set('q', trimmed)
        } else {
            urlParams.delete('q')
        }
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url', 'q'],
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
    }, [])

    
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
            
            {/* Primary Toolbar Row */}
            <div className="px-3 py-2.5 sm:px-4">
                <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    {/* Search: decoupled component keeps focus across Inertia reloads; stable key */}
                    <div className="flex flex-wrap items-center gap-2 min-w-0">
                        <div className="w-full sm:flex-1 sm:min-w-[180px] max-w-full sm:max-w-sm">
                            <AssetGridSearchInput
                                key="asset-grid-search"
                                serverQuery={serverQ}
                                onSearchApply={applySearch}
                                isSearchPending={searchLoading}
                                placeholder="Search items…"
                                inputClassName="py-1 text-xs sm:text-sm"
                                inputRef={searchInputRef}
                            />
                        </div>
                        
                        {/* Primary Metadata Filters - Between search and controls */}
                        <div className="flex items-center gap-2 min-w-0">
                            <AssetGridMetadataPrimaryFilters
                                filterable_schema={filterable_schema}
                                selectedCategoryId={selectedCategoryId}
                                available_values={available_values}
                                assetType="image"
                                compact={true}
                            />
                            
                            {/* When no More filters bar: show Sort in toolbar (e.g. Collections) */}
                        </div>
                        {onSortChange && !showMoreFilters && (
                            <div className="flex items-center gap-1 flex-shrink-0 ml-auto sm:ml-0">
                                <span className="text-xs font-medium text-gray-500">Sort</span>
                                <select
                                    value={sortBy}
                                    onChange={(e) => onSortChange(e.target.value, sortDirection)}
                                    className="min-w-[7.5rem] rounded border border-gray-300 bg-white py-1 pl-2 pr-6 text-xs sm:text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    aria-label="Sort by"
                                >
                                    <option value="featured">Featured</option>
                                    <option value="created">Created</option>
                                    <option value="quality">Quality</option>
                                    <option value="modified">Modified</option>
                                    <option value="alphabetical">Alphabetical</option>
                                </select>
                                <button
                                    type="button"
                                    onClick={() => onSortChange(sortBy, sortDirection === 'asc' ? 'desc' : 'asc')}
                                    className="p-1 rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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

                    {/* Controls - Right Side on Desktop */}
                    <div className="flex flex-wrap items-center gap-2 lg:flex-nowrap lg:justify-end">
                        {/* Phase 2 – Step 7: Bulk Actions (only in main row when no More filters bar, e.g. Collections) */}
                        {onToggleBulkMode && !showMoreFilters && (
                            <>
                                <button
                                    type="button"
                                    onClick={onToggleBulkMode}
                                    className={`px-2.5 py-1.5 text-xs sm:text-sm font-medium rounded-md transition-colors ${
                                        isBulkMode
                                            ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'
                                            : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    {isBulkMode ? 'Cancel Selection' : 'Select Multiple'}
                                </button>
                                {isBulkMode && bulkSelectedCount > 0 && onBulkEdit && (
                                    <button
                                        type="button"
                                        onClick={onBulkEdit}
                                        className="px-2.5 py-1.5 text-xs sm:text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                    >
                                        Edit Metadata ({bulkSelectedCount})
                                    </button>
                                )}
                            </>
                        )}
                        {/* Phase D1: Select all (only in main row when no More filters bar) */}
                        {showSelectAllForDownload && onSelectAllForDownload && !showMoreFilters && (
                            <button
                                type="button"
                                onClick={onSelectAllForDownload}
                                className="px-2.5 py-1.5 text-xs sm:text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                            >
                                Select all
                            </button>
                        )}

                        {/* Show Info Toggle */}
                        <label className="flex items-center gap-2 cursor-pointer">                        
                            <InformationCircleIcon className="h-4 w-4 text-gray-700" title="Show info" />

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

                        {/* Tile style toggle (guidelines vs default) - testing only */}
                        {onCardStyleChange && (
                            <button
                                type="button"
                                onClick={() => onCardStyleChange(cardStyle === 'guidelines' ? 'default' : 'guidelines')}
                                className={`flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md border transition-colors ${
                                    cardStyle === 'guidelines'
                                        ? 'bg-indigo-50 text-indigo-700 border-indigo-200 hover:bg-indigo-100'
                                        : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'
                                }`}
                                title={cardStyle === 'guidelines' ? 'Guidelines style (click for default)' : 'Default style (click for guidelines)'}
                                aria-label="Toggle tile style"
                            >
                                <SwatchIcon className="h-4 w-4" />
                                <span className="hidden sm:inline">{cardStyle === 'guidelines' ? 'Guidelines' : 'Default'}</span>
                            </button>
                        )}

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
                                                px-2.5 py-1.5 text-xs sm:text-sm font-medium transition-all
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
                        </div>
                    </div>
                </div>
            </div>

            {/* More Filters Section — More filters stays far left; selection buttons live in bar right (via barTrailingContent from parent) */}
            {showMoreFilters && moreFiltersContent && (
                <div className="border-t border-gray-200">
                    {moreFiltersContent}
                </div>
            )}
        </div>
    )
}