/**
 * Asset Grid Primary Filters Component
 * 
 * Primary filter bar for the asset grid.
 * Renders only visible primary filters: Search, Category, Asset Type, Brand.
 * 
 * This component uses existing filter helpers:
 * - normalizeFilterConfig: Normalizes Inertia props to determine is_multi_brand
 * 
 * Note: Primary filters (Search, Category, Asset Type, Brand) are hardcoded UI controls,
 * not metadata fields. They are always visible (except Brand which is conditional on is_multi_brand).
 * 
 * ⚠️ CONSTRAINTS:
 * - React component only (render-only)
 * - No backend changes
 * - No resolver changes
 * - No helper modifications
 * - Use existing helpers as-is
 * 
 * @module AssetGridPrimaryFilters
 */

import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { usePage, router } from '@inertiajs/react'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
import { DELIVERABLES_PAGE_LABEL } from '../utils/uiLabels'

/** UUID regex: 8-4-4-4-12 hex with optional dashes. Matches asset IDs. */
const UUID_REGEX = /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i

/** Extract asset ID from search string if it looks like a UUID (bare, id:uuid, or asset:uuid). */
function extractAssetIdFromSearch(q) {
    const trimmed = (q || '').trim()
    if (!trimmed) return null
    if (UUID_REGEX.test(trimmed)) return trimmed
    const m = trimmed.match(/^(?:id|asset):\s*(.+)$/i)
    if (m) {
        const candidate = m[1].trim()
        if (UUID_REGEX.test(candidate)) return candidate
    }
    return null
}

/**
 * Primary Filter Bar Component
 * 
 * Renders primary filters: Search, Category, Asset Type, Brand (if multi-brand).
 * 
 * Uses normalizeFilterConfig to determine multi-brand context.
 * Primary filters are always visible (they're UI controls, not metadata fields).
 * 
 * @param {Object} props
 * @param {Array} props.categories - Available categories
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Function} props.onCategoryChange - Callback when category changes
 * @param {string} props.assetType - Current asset type (defaults to 'asset')
 * @param {boolean} props.showAllButton - Whether to show "All Categories" button
 */
export default function AssetGridPrimaryFilters({
    categories = [],
    selectedCategoryId = null,
    onCategoryChange = null,
    assetType = 'asset',
    showAllButton = false,
}) {
    const pageProps = usePage().props
    const { auth } = pageProps
    
    // Normalize filter config using existing helper
    // This ensures consistent shape and determines is_multi_brand
    const normalizedConfig = useMemo(() => {
        return normalizeFilterConfig({
            auth: pageProps.auth,
            selected_category: selectedCategoryId,
            asset_type: assetType,
            filterable_schema: [], // Primary filters don't come from schema
            available_values: {}, // Primary filters don't need available values
        })
    }, [pageProps.auth, selectedCategoryId, assetType])
    
    // Search state (debounced)
    const [searchQuery, setSearchQuery] = useState('')
    const [debouncedSearch, setDebouncedSearch] = useState('')
    
    // Track if component has mounted to prevent initial loop
    const isMountedRef = useRef(false)
    const initialSearchRef = useRef(null)
    
    // Get search query from URL on mount (backend uses ?q=)
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search)
        const q = urlParams.get('q') || (typeof pageProps.q === 'string' ? pageProps.q : '') || ''
        setSearchQuery(q)
        setDebouncedSearch(q)
        initialSearchRef.current = q
        isMountedRef.current = true
    }, [])
    
    // Debounce search input (300ms delay)
    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearch(searchQuery)
        }, 300)
        
        return () => clearTimeout(timer)
    }, [searchQuery])
    
    // Update URL when debounced search changes (but not on initial mount)
    useEffect(() => {
        // Skip if not mounted yet or if this is the initial value
        if (!isMountedRef.current || debouncedSearch === initialSearchRef.current) {
            return
        }
        
        const urlParams = new URLSearchParams(window.location.search)
        const trimmed = debouncedSearch.trim()
        if (trimmed) {
            urlParams.set('q', trimmed)
            const assetId = extractAssetIdFromSearch(trimmed)
            if (assetId) {
                urlParams.set('asset', assetId)
            } else {
                urlParams.delete('asset')
            }
        } else {
            urlParams.delete('q')
            urlParams.delete('asset')
        }
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url', 'q'],
        })
    }, [debouncedSearch])
    
    // Check if any filters are active
    const hasActiveFilters = useMemo(() => {
        return debouncedSearch.trim().length > 0
    }, [debouncedSearch])
    
    // Handle clear all filters
    const handleClearAll = useCallback(() => {
        setSearchQuery('')
        setDebouncedSearch('')
        
        const urlParams = new URLSearchParams(window.location.search)
        urlParams.delete('q')
        urlParams.delete('asset')
        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url', 'q'],
        })
    }, [])
    
    // Handle category change
    const handleCategoryChange = useCallback((category) => {
        if (onCategoryChange) {
            onCategoryChange(category)
        }
    }, [onCategoryChange])
    
    // Get brand selector visibility
    const showBrandSelector = normalizedConfig.is_multi_brand && auth.brands && auth.brands.length > 1
    
    return (
        <div className="bg-white border-b border-gray-200 px-4 py-3 sm:px-6">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                {/* Primary Filters Row */}
                <div className="flex flex-1 items-center gap-3 flex-wrap">
                    {/* Search Input */}
                    <div className="relative flex-1 min-w-[200px] max-w-md">
                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <MagnifyingGlassIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search assets… or paste asset ID"
                            className="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                className="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <XMarkIcon className="h-5 w-5 text-gray-400 hover:text-gray-600" />
                            </button>
                        )}
                    </div>
                    
                    {/* Category Selector */}
                    <div className="flex-shrink-0">
                        <select
                            value={selectedCategoryId || ''}
                            onChange={(e) => {
                                const categoryId = e.target.value ? parseInt(e.target.value, 10) : null
                                // Only trigger change if category actually changed
                                if (categoryId !== selectedCategoryId) {
                                    const category = categories.find(c => c.id === categoryId)
                                    handleCategoryChange(category || null)
                                }
                            }}
                            className="block w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                        >
                            {showAllButton && (
                                <option value="">All Categories</option>
                            )}
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    
                    {/* Asset Type Selector */}
                    <div className="flex-shrink-0">
                        <select
                            value={assetType}
                            onChange={(e) => {
                                // Asset type changes trigger navigation
                                const newAssetType = e.target.value
                                router.get(`/app/${newAssetType === 'deliverable' ? 'deliverables' : 'assets'}`, {}, {
                                    preserveState: false,
                                    preserveScroll: false,
                                })
                            }}
                            className="block w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                        >
                            <option value="asset">Assets</option>
                            <option value="deliverable">{DELIVERABLES_PAGE_LABEL}</option>
                        </select>
                    </div>
                    
                    {/* Brand Selector (only if multi-brand) */}
                    {showBrandSelector && (
                        <div className="flex-shrink-0">
                            <select
                                value={auth.activeBrand?.id || ''}
                                onChange={(e) => {
                                    const brandId = parseInt(e.target.value, 10)
                                    router.post(`/app/brands/${brandId}/switch`, {}, {
                                        preserveState: true,
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            router.reload({ only: ['auth'] })
                                        },
                                    })
                                }}
                                className="block w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                            >
                                {auth.brands
                                    .filter(brand => !brand.is_disabled)
                                    .map((brand) => (
                                        <option key={brand.id} value={brand.id}>
                                            {brand.name}
                                        </option>
                                    ))}
                            </select>
                        </div>
                    )}
                </div>
                
                {/* Clear All Button (only shown when filters are active) */}
                {hasActiveFilters && (
                    <div className="flex-shrink-0">
                        <button
                            type="button"
                            onClick={handleClearAll}
                            className="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <XMarkIcon className="h-4 w-4 mr-1.5" />
                            Clear All
                        </button>
                    </div>
                )}
            </div>
        </div>
    )
}
