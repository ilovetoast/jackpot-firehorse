import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import { useAssetReconciliation } from '../../hooks/useAssetReconciliation'
import { useThumbnailSmartPoll } from '../../hooks/useThumbnailSmartPoll'
import { usePermission } from '../../hooks/usePermission'
import { useInfiniteLoad } from '../../hooks/useInfiniteLoad'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import AppNav from '../../Components/AppNav'
import AddAssetButton from '../../Components/AddAssetButton'
import UploadAssetDialog from '../../Components/UploadAssetDialog'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import { useBucket } from '../../contexts/BucketContext'
import { mergeAsset, warnIfOverwritingCompletedThumbnail } from '../../utils/assetUtils'
import { DELIVERABLES_ITEM_LABEL, DELIVERABLES_ITEM_LABEL_PLURAL } from '../../utils/uiLabels'
import {
    TagIcon,
    SparklesIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

export default function DeliverablesIndex({ categories, selected_category, show_all_button = false, assets = [], filterable_schema = [], available_values = {}, sort = 'created', sort_direction = 'desc', q: searchQuery = '' }) {
    const pageProps = usePage().props
    const { auth } = pageProps
    const { hasPermission: canUpload } = usePermission('asset.upload')
    
    const [selectedCategoryId, setSelectedCategoryId] = useState(selected_category ? parseInt(selected_category) : null)
    const [tooltipVisible, setTooltipVisible] = useState(null)
    const [mobileCategoriesOpen, setMobileCategoriesOpen] = useState(false)
    
    // FINAL FIX: Remount key to force page remount after finalize
    const [remountKey, setRemountKey] = useState(0)
    
    // Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
    // Do not convert back to prop-based visibility.
    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false)
    
    // Prevent reopening dialog during auto-close timeout (400-700ms delay)
    const [isAutoClosing, setIsAutoClosing] = useState(false)
    
    // Local asset state (same pattern as Assets/Index)
    const [localAssets, setLocalAssets] = useState(assets)
    
    // Track previous category ID to detect category changes
    // When category changes, we should replace assets entirely (not merge incompatible sets)
    const prevCategoryIdForAssetsRef = useRef(selectedCategoryId)
    
    // Update local assets when props change (e.g., after Inertia reload)
    // Guard: Protect completed thumbnails from being overwritten on refresh
    useEffect(() => {
        const prevCategoryId = prevCategoryIdForAssetsRef.current
        const currentCategoryId = selectedCategoryId
        const categoryChanged = prevCategoryId !== currentCategoryId
        
        // Update ref for next comparison
        prevCategoryIdForAssetsRef.current = currentCategoryId
        
        setLocalAssets(prevAssets => {
            // If no previous assets, use new assets as-is
            if (!prevAssets || prevAssets.length === 0) {
                return assets
            }
            
            // CRITICAL: If category changed, replace assets entirely (don't merge)
            // This prevents merging incompatible asset sets from different categories
            // which would cause a double flash (old assets briefly visible before new ones)
            if (categoryChanged) {
                return assets
            }
            
            // Category didn't change - merge new assets with field-level protection
            return assets.map(newAsset => {
                const prevAsset = prevAssets.find(a => a.id === newAsset.id)
                if (!prevAsset) {
                    return newAsset
                }
                
                // Dev warning if attempting to overwrite completed thumbnail
                warnIfOverwritingCompletedThumbnail(prevAsset, newAsset, 'refresh-sync')
                
                // Field-level merge: protects thumbnail fields but allows title, filename, metadata updates
                return mergeAsset(prevAsset, newAsset)
            })
        })
        
        // Clear staleness flag when assets prop is reloaded (grid is now synced)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
    }, [assets, selectedCategoryId])
    
    // Store only asset ID to prevent stale object references after Inertia reloads
    // The active asset is derived from the current assets array, ensuring it always reflects fresh data
    const [activeAssetId, setActiveAssetId] = useState(null) // Asset ID selected for drawer
    
    // Derive active asset from local assets array to prevent stale references
    // CRITICAL: Drawer identity is based ONLY on activeAssetId, not asset object identity
    // Asset object mutations (async updates, thumbnail swaps, etc.) must NOT close the drawer
    const activeAsset = activeAssetId ? localAssets.find(asset => asset.id === activeAssetId) : null
    
    // Close drawer ONLY if active asset ID truly doesn't exist in current assets array
    // This check is robust against temporary nulls during async updates
    // We check for ID existence, not object reference equality
    useEffect(() => {
        if (activeAssetId) {
            const assetExists = localAssets.some(asset => asset.id === activeAssetId)
            if (!assetExists) {
                // Asset ID no longer exists in array - close drawer
                setActiveAssetId(null)
            }
        }
    }, [activeAssetId, localAssets])

    // Phase D1: Download bucket from app-level context so the bar does not remount on category change (no flash)
    const { bucketAssetIds, bucketAdd, bucketRemove, bucketClear, bucketAddBatch, clearIfEmpty } = useBucket()

    useEffect(() => {
        clearIfEmpty(pageProps.download_bucket_count ?? 0)
    }, [pageProps.download_bucket_count, clearIfEmpty])

    const handleBucketToggle = useCallback((assetId) => {
        if (bucketAssetIds.includes(assetId)) bucketRemove(assetId)
        else bucketAdd(assetId)
    }, [bucketAssetIds, bucketAdd, bucketRemove])

    const visibleIds = useMemo(() => (localAssets || []).map((a) => a.id).filter(Boolean), [localAssets])
    const allVisibleInBucket = visibleIds.length > 0 && visibleIds.every((id) => bucketAssetIds.includes(id))
    const handleSelectAllToggle = useCallback(async () => {
        if (visibleIds.length === 0) return
        if (allVisibleInBucket) {
            for (const id of visibleIds) {
                await bucketRemove(id)
            }
        } else {
            await bucketAddBatch(visibleIds)
        }
    }, [visibleIds, allVisibleInBucket, bucketAddBatch, bucketRemove])

    // Category switches should reset the drawer selection
    // but must NOT remount the entire page (that destroys <img> nodes and causes flashes).
    // Match Assets/Index behavior: don't clear localAssets immediately - let the assets
    // useEffect handle category changes by replacing (not merging) when category changed.
    useEffect(() => {
        setActiveAssetId(null)
        
        // Clear staleness flag when category changes (view is synced with new category)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
    }, [selectedCategoryId])
    
    // Open drawer from URL query parameter (e.g., ?asset={id}&edit_metadata={field_id})
    // Also clear staleness flag on mount (navigation to /app/deliverables completes)
    useEffect(() => {
        // Clear staleness flag when navigating to deliverables page (view is synced)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
        
        if (typeof window !== 'undefined') {
            const urlParams = new URLSearchParams(window.location.search)
            const assetId = urlParams.get('asset')
            const editMetadataFieldId = urlParams.get('edit_metadata')
            
            if (assetId && localAssets.length > 0) {
                const asset = localAssets.find(a => a.id === assetId)
                if (asset) {
                    setActiveAssetId(assetId)
                    // If edit_metadata param is present, the drawer will handle it
                    // (AssetDrawer or AssetMetadataDisplay should read this)
                }
            }
        }
    }, [localAssets]) // Re-check when assets load
    
    // Phase 3.1: Background Asset Reconciliation
    // DISABLED: useThumbnailSmartPoll handles async updates without reloads
    // useAssetReconciliation uses router.reload() which causes grid remounts
    // Async processing (thumbnails, AI, video posters) must NEVER call router.reload
    // Incremental load: show 24 initially, load more on scroll or button click
    const infiniteResetDeps = [selectedCategoryId, typeof window !== 'undefined' ? window.location.search : '']
    const { visibleItems, loadMore, hasMore } = useInfiniteLoad(localAssets, 24, infiniteResetDeps)

    // useThumbnailSmartPoll updates localAssets in-place via handleThumbnailUpdate callback
    // This matches Assets/Index behavior - reconciliation is disabled there too
    // useAssetReconciliation({
    //     assets,
    //     selectedCategoryId,
    //     isPaused: isUploadDialogOpen,
    // })
    
    // Grid thumbnail polling: Async updates for fade-in (same as drawer)
    // No view refreshes - only local state updates
    const handleThumbnailUpdate = useCallback((updatedAsset) => {
        setLocalAssets(prevAssets => {
            return prevAssets.map(asset => {
                if (asset.id === updatedAsset.id) {
                    // Merge updated asset data (async, no refresh)
                    return mergeAsset(asset, updatedAsset)
                }
                return asset
            })
        })
    }, [])
    
    // Handle lifecycle updates (publish/unpublish) - updates local state without full reload
    // This preserves drawer state and grid scroll position (matches Assets/Index behavior)
    const handleLifecycleUpdate = useCallback((updatedAsset) => {
        setLocalAssets(prevAssets => {
            return prevAssets.map(asset => {
                if (asset.id === updatedAsset.id) {
                    // Merge updated asset data (preserves thumbnail state, updates lifecycle fields)
                    return mergeAsset(asset, updatedAsset)
                }
                return asset
            })
        })
    }, [])
    
    useThumbnailSmartPoll({
        assets: localAssets,
        onAssetUpdate: handleThumbnailUpdate,
        selectedCategoryId,
    })
    
    // Track drawer animation state to freeze grid layout during animation
    // CSS Grid recalculates columns immediately on width change, causing mid-animation reflow
    // By delaying padding change until after animation (300ms), grid recalculates once cleanly
    const [isDrawerAnimating, setIsDrawerAnimating] = useState(false)
    
    // Separate layout concerns (drawer visibility) from content concerns (active asset)
    // Grid layout changes should only trigger on drawer open/close, not on asset changes
    // This prevents grid rescaling when switching assets while drawer is already open
    const isDrawerOpen = !!activeAsset
    const prevDrawerOpenRef = useRef(isDrawerOpen)
    
    useEffect(() => {
        const prevDrawerOpen = prevDrawerOpenRef.current
        const drawerVisibilityChanged = prevDrawerOpen !== isDrawerOpen
        
        // Only trigger animation logic when drawer visibility changes (open/close)
        // Asset changes while drawer is open are content swaps, not layout events
        if (drawerVisibilityChanged) {
            if (isDrawerOpen) {
                // Drawer opening - delay padding change to prevent mid-animation grid reflow
                setIsDrawerAnimating(true)
                const timer = setTimeout(() => {
                    setIsDrawerAnimating(false)
                }, 300) // Match transition duration
                prevDrawerOpenRef.current = isDrawerOpen
                return () => clearTimeout(timer)
            } else {
                // Drawer closing - apply padding change immediately for clean close
                setIsDrawerAnimating(false)
                prevDrawerOpenRef.current = isDrawerOpen
            }
        }
    }, [isDrawerOpen])
    
    // Load toolbar settings from localStorage
    const getStoredCardSize = () => {
        if (typeof window === 'undefined') return 220
        const stored = localStorage.getItem('assetGridCardSize')
        return stored ? parseInt(stored, 10) : 220
    }
    
    const getStoredShowInfo = () => {
        if (typeof window === 'undefined') return true
        const stored = localStorage.getItem('assetGridShowInfo')
        return stored ? stored === 'true' : true
    }
    
    // Card size with scaling enabled - loads from localStorage
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    
    // Save card size to localStorage when it changes
    useEffect(() => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('assetGridCardSize', cardSize.toString())
        }
    }, [cardSize])
    
    useEffect(() => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('assetGridShowInfo', showInfo.toString())
        }
    }, [showInfo])

    // Handle category selection - triggers Inertia reload with slug-based category query param (?category=slug)
    // ARCHITECTURAL: Reload filterable_schema and available_values so primary/secondary filters match the selected category (same as Assets).
    const handleCategorySelect = (category) => {
        const categoryId = category?.id ?? category // Support both object and ID for backward compatibility
        const categorySlug = category?.slug ?? null
        
        // Phase 2 invariant: Explicitly reset dialog state before preserveState navigation
        // This prevents Inertia from preserving isUploadDialogOpen=true across category changes
        setIsUploadDialogOpen(false)
        
        setSelectedCategoryId(categoryId)
        
        router.get('/app/deliverables', 
            categorySlug ? { category: categorySlug } : {},
            { 
                preserveState: true, 
                preserveScroll: true,
                only: ['filterable_schema', 'available_values', 'assets', 'selected_category', 'selected_category_slug']
            }
        )
    }

    // Get brand sidebar color (nav_color) for sidebar background, fallback to primary color
    const sidebarColor = auth.activeBrand?.nav_color || auth.activeBrand?.primary_color || '#1f2937' // Default to gray-800 if no brand color
    const isLightColor = (color) => {
        if (!color || color === '#ffffff' || color === '#FFFFFF') return true
        const hex = color.replace('#', '')
        const r = parseInt(hex.substr(0, 2), 16)
        const g = parseInt(hex.substr(2, 2), 16)
        const b = parseInt(hex.substr(4, 2), 16)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
        return luminance > 0.5
    }
    const textColor = isLightColor(sidebarColor) ? '#000000' : '#ffffff'
    const hoverBgColor = isLightColor(sidebarColor) ? 'rgba(0, 0, 0, 0.1)' : 'rgba(255, 255, 255, 0.1)'
    const activeBgColor = isLightColor(sidebarColor) ? 'rgba(0, 0, 0, 0.2)' : 'rgba(255, 255, 255, 0.2)'
    
    // Key only by remountKey so category changes do NOT remount (avoids double flash).
    // Assets does not use a category-dependent key; matching that here fixes Executions flashing twice.
    const pageKey = `deliverables-${remountKey}`
    
    // Handle finalize complete - refresh asset grid after successful upload finalize
    // Match Assets/Index behavior: preserve drawer state by only reloading assets prop
    const handleFinalizeComplete = useCallback(() => {
        // Set auto-closing flag to prevent reopening during timeout
        setIsAutoClosing(true)
        
        // Ensure dialog stays closed during reload
        setIsUploadDialogOpen(false)
        
        // Force page remount by incrementing remount key
        setRemountKey(prev => prev + 1)
        
        // Reload assets to show newly uploaded assets
        // Match Assets/Index: preserveState: false prevents dialog reopening, but drawer state (activeAssetId) is preserved in component state
        router.reload({ 
            only: ['assets'], 
            preserveScroll: true,
            preserveState: false, // Prevent state preservation to avoid dialog reopening
            onSuccess: () => {
                setIsUploadDialogOpen(false)
                // Reset auto-closing flag after reload completes
                setIsAutoClosing(false)
            }
        })
    }, [])
    
    // Drag-and-drop state for files dropped on grid
    const [droppedFiles, setDroppedFiles] = useState(null)
    const [isDraggingOver, setIsDraggingOver] = useState(false)
    
    // BUGFIX: Single handler to open upload dialog
    const handleOpenUploadDialog = useCallback((files = null) => {
        // Prevent opening if auto-close is in progress
        if (isAutoClosing) {
            return
        }
        // Store dropped files if provided
        if (files) {
            setDroppedFiles(files)
        }
        setIsUploadDialogOpen(true)
    }, [isAutoClosing])
    
    // BUGFIX: Single handler to close upload dialog
    const handleCloseUploadDialog = useCallback(() => {
        setIsUploadDialogOpen(false)
        setIsAutoClosing(false) // Reset flag if manually closed
        setDroppedFiles(null) // Clear dropped files when dialog closes
    }, [])
    
    // Handle drag-and-drop on grid area
    const handleDragOver = useCallback((e) => {
        // Only allow drag-over if user can upload
        if (!canUpload) {
            return
        }
        e.preventDefault()
        e.stopPropagation()
        // Only show drag overlay if dragging files (not other elements)
        if (e.dataTransfer.types.includes('Files')) {
            setIsDraggingOver(true)
        }
    }, [canUpload])
    
    const handleDragEnter = useCallback((e) => {
        // Only allow drag-enter if user can upload
        if (!canUpload) {
            return
        }
        e.preventDefault()
        e.stopPropagation()
        // Only show drag overlay if dragging files (not other elements)
        if (e.dataTransfer.types.includes('Files')) {
            setIsDraggingOver(true)
        }
    }, [canUpload])
    
    const handleDragLeave = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        // Only clear drag state if we're leaving the drop zone entirely
        // (not just moving between child elements)
        if (!e.currentTarget.contains(e.relatedTarget)) {
            setIsDraggingOver(false)
        }
    }, [])
    
    const handleDrop = useCallback((e) => {
        // Only allow drop if user can upload
        if (!canUpload) {
            return
        }
        e.preventDefault()
        e.stopPropagation()
        setIsDraggingOver(false) // Clear drag state on drop
        
        const files = Array.from(e.dataTransfer.files || [])
        if (files.length > 0) {
            // Filter to only image files (or adjust as needed)
            const imageFiles = files.filter(file => file.type.startsWith('image/') || file.type.startsWith('video/') || file.type === 'application/pdf')
            if (imageFiles.length > 0) {
                handleOpenUploadDialog(imageFiles)
            }
        }
    }, [canUpload, handleOpenUploadDialog])

    return (
        <div key={pageKey} className="h-screen flex flex-col overflow-hidden" data-category-id={selectedCategoryId ?? 'all'}>
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-72 h-full" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                            <nav className="mt-5 flex-1 px-2 space-y-1">
                                {/* Add Execution Button - Persistent in sidebar (only show if user has upload permissions) */}
                                {auth?.user && (
                                    <div className="px-3 py-2 mb-4">
                                        <AddAssetButton 
                                            defaultAssetType="deliverable" 
                                            className="w-full"
                                            onClick={handleOpenUploadDialog}
                                        />
                                    </div>
                                )}
                                
                                {/* Categories */}
                                <div className="px-3 py-2">
                                    <h3 className="px-3 text-xs font-semibold uppercase tracking-wider" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                        Categories
                                    </h3>
                                    <div className="mt-2 space-y-1">
                                        {/* "All" button - only shown for non-free plans */}
                                        {show_all_button && (
                                            <button
                                                onClick={() => handleCategorySelect(null)}
                                                className="group flex items-center px-3 py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: selectedCategoryId === null ? activeBgColor : 'transparent',
                                                    color: textColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (selectedCategoryId !== null) {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (selectedCategoryId !== null) {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                    }
                                                }}
                                            >
                                                <TagIcon className="mr-3 flex-shrink-0 h-5 w-5" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }} />
                                                All
                                            </button>
                                        )}
                                        {categories.length > 0 ? (
                                            categories
                                                .filter(category => {
                                                    // Filter out hidden categories from sidebar
                                                    // Explicitly check for truthy values that indicate hidden
                                                    if (category.is_hidden === true || category.is_hidden === 1 || category.is_hidden === '1' || category.is_hidden === 'true') {
                                                        return false; // Hide this category
                                                    }
                                                    return true; // Show this category
                                                })
                                                .map((category) => (
                                                <button
                                                    key={category.id || `template-${category.slug}-${category.asset_type}`}
                                                    onClick={() => handleCategorySelect(category)}
                                                    className="group flex items-center px-3 py-2 text-sm font-medium rounded-md w-full text-left"
                                                    style={{
                                                        backgroundColor: selectedCategoryId === category.id ? activeBgColor : 'transparent',
                                                        color: textColor,
                                                    }}
                                                    onMouseEnter={(e) => {
                                                        if (selectedCategoryId !== category.id) {
                                                            e.currentTarget.style.backgroundColor = hoverBgColor
                                                        }
                                                    }}
                                                    onMouseLeave={(e) => {
                                                        if (selectedCategoryId !== category.id) {
                                                            e.currentTarget.style.backgroundColor = 'transparent'
                                                        }
                                                    }}
                                                >
                                                    <CategoryIcon 
                                                        iconId={category.icon || 'folder'} 
                                                        className="mr-3 flex-shrink-0 h-5 w-5" 
                                                        style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}
                                                    />
                                                    <span className="flex-1">{category.name}</span>
                                                    {category.is_private && (
                                                        <div className="relative ml-2 group">
                                                            <LockClosedIcon 
                                                                className="h-4 w-4 flex-shrink-0 cursor-help" 
                                                                style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}
                                                                onMouseEnter={() => setTooltipVisible(category.id || `template-${category.slug}-${category.asset_type}`)}
                                                                onMouseLeave={() => setTooltipVisible(null)}
                                                            />
                                                            {tooltipVisible === (category.id || `template-${category.slug}-${category.asset_type}`) && (
                                                                <div 
                                                                    className="absolute right-full mr-2 top-1/2 transform -translate-y-1/2 bg-gray-900 text-white text-xs rounded-lg shadow-xl z-[9999] pointer-events-none whitespace-normal"
                                                                    style={{
                                                                        transform: 'translateY(-50%)',
                                                                        width: '250px',
                                                                    }}
                                                                >
                                                                    <div className="p-3">
                                                                        <div className="font-semibold mb-2.5 text-white">Restricted Category</div>
                                                                        <div className="space-y-2">
                                                                            <div className="text-gray-200">Accessible by:</div>
                                                                            <ul className="list-disc list-outside ml-4 space-y-1 text-gray-200">
                                                                                <li>Owners</li>
                                                                                <li>Admins</li>
                                                                                {category.access_rules && category.access_rules.length > 0 && category.access_rules
                                                                                    .filter(rule => rule.type === 'role')
                                                                                    .map((rule, idx) => (
                                                                                        <li key={idx} className="capitalize">{rule.role.replace('_', ' ')}</li>
                                                                                    ))
                                                                                }
                                                                            </ul>
                                                                        </div>
                                                                    </div>
                                                                    <div className="absolute left-full top-1/2 transform -translate-y-1/2 w-0 h-0 border-t-[6px] border-b-[6px] border-l-[6px] border-transparent border-l-gray-900"></div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </button>
                                            ))
                                        ) : (
                                            <div className="px-3 py-2 text-sm" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                                No execution categories yet
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </nav>
                        </div>
                    </div>
                </div>

                {/* Mobile: Categories slide-out (visible when lg:hidden) */}
                {mobileCategoriesOpen && (
                    <>
                        <div className="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden" aria-hidden="true" onClick={() => setMobileCategoriesOpen(false)} />
                        <div className="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] flex flex-col shadow-xl lg:hidden" style={{ backgroundColor: sidebarColor, top: '5rem' }} role="dialog" aria-modal="true" aria-label="Categories">
                            <div className="flex items-center justify-between h-14 px-4 border-b shrink-0" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.1)' }}>
                                <span className="text-sm font-semibold" style={{ color: textColor }}>Categories</span>
                                <button type="button" onClick={() => setMobileCategoriesOpen(false)} className="rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white/50" style={{ color: textColor }} aria-label="Close categories">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <div className="flex-1 overflow-y-auto py-4 px-2">
                                {auth?.user && (
                                    <div className="px-2 py-2 mb-3">
                                        <AddAssetButton defaultAssetType="deliverable" className="w-full" onClick={() => { handleOpenUploadDialog(); setMobileCategoriesOpen(false) }} />
                                    </div>
                                )}
                                <div className="space-y-0.5">
                                    {show_all_button && (
                                        <button onClick={() => { handleCategorySelect(null); setMobileCategoriesOpen(false) }} className="flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left" style={{ backgroundColor: selectedCategoryId === null ? activeBgColor : 'transparent', color: textColor }}>
                                            <TagIcon className="mr-3 h-5 w-5 opacity-60" style={{ color: textColor }} /><span className="flex-1">All</span>
                                        </button>
                                    )}
                                    {categories.length > 0 && categories.filter(c => !(c.is_hidden === true || c.is_hidden === 1 || c.is_hidden === '1' || c.is_hidden === 'true')).map((category) => (
                                        <button key={category.id || `template-${category.slug}-${category.asset_type}`} onClick={() => { handleCategorySelect(category); setMobileCategoriesOpen(false) }} className="flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left" style={{ backgroundColor: selectedCategoryId === category.id ? activeBgColor : 'transparent', color: textColor }}>
                                            <CategoryIcon iconId={category.icon || 'folder'} className="mr-3 h-5 w-5 opacity-60" style={{ color: textColor }} /><span className="flex-1">{category.name}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </>
                )}

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative flex flex-col">
                    <div className="lg:hidden flex items-center gap-2 py-2 px-4 sm:px-6 border-b border-gray-200 bg-white/80 backdrop-blur-sm shrink-0">
                        <button type="button" onClick={() => setMobileCategoriesOpen(true)} className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                            <svg className="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                            <span>Categories</span>
                            {selectedCategoryId != null && categories.find(c => c.id === selectedCategoryId) && <span className="text-gray-500 truncate max-w-[120px]">— {categories.find(c => c.id === selectedCategoryId).name}</span>}
                        </button>
                    </div>
                    <div 
                        className={`flex-1 min-h-0 overflow-y-auto transition-[padding-right] duration-300 ease-in-out relative ${bucketAssetIds.length > 0 ? 'pb-24' : ''}`}
                        style={{ 
                            // Freeze grid layout during drawer animation to prevent mid-animation reflow
                            // CSS Grid recalculates columns immediately on width change
                            // By delaying padding change until after animation, we get one controlled snap instead of dropping items mid-animation
                            // Use isDrawerOpen (not activeAsset) to prevent layout changes on asset swaps
                            paddingRight: (isDrawerOpen && !isDrawerAnimating) ? '480px' : '0' 
                        }}
                        onDragOver={canUpload ? handleDragOver : undefined}
                        onDragEnter={canUpload ? handleDragEnter : undefined}
                        onDragLeave={canUpload ? handleDragLeave : undefined}
                        onDrop={canUpload ? handleDrop : undefined}
                    >
                        {/* Drag and drop overlay */}
                        {isDraggingOver && (() => {
                            const primaryColor = auth.activeBrand?.primary_color || '#6366f1'
                            // Ensure color has # prefix, then add 60% opacity (99 in hex = ~60%)
                            const colorWithOpacity = primaryColor.startsWith('#') 
                                ? `${primaryColor}99` 
                                : `#${primaryColor}99`
                            
                            return (
                                <div 
                                    className="absolute inset-0 z-50 flex items-center justify-center pointer-events-none"
                                    style={{
                                        backgroundColor: colorWithOpacity,
                                    }}
                                >
                                    <div className="text-center">
                                        <div className="text-2xl font-semibold text-white mb-2">
                                            Drag and drop here...
                                        </div>
                                        <div className="text-lg text-white opacity-90">
                                            Release to upload files
                                        </div>
                                    </div>
                                </div>
                            )
                        })()}
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                        {/* Asset Grid Toolbar - Always visible (persists across categories) */}
                        {/* Matches Assets/Index behavior - toolbar always visible, even when no assets */}
                        <div className="mb-8">
                            <AssetGridToolbar
                                showInfo={showInfo}
                                onToggleInfo={() => setShowInfo(v => !v)}
                                cardSize={cardSize}
                                onCardSizeChange={setCardSize}
                                primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                filterable_schema={filterable_schema}
                                selectedCategoryId={selectedCategoryId}
                                available_values={available_values}
                                showMoreFilters={true}
                                moreFiltersContent={
                                    /* Secondary Metadata Filters - Renders metadata fields with is_primary !== true */
                                    /* 
                                        Secondary metadata filters are metadata fields NOT marked as primary.
                                        These filters render in the "More filters" expandable section.
                                        
                                        Visibility rules (enforced by Phase H helpers):
                                        - Field does NOT have is_primary === true (excluded from primary)
                                        - Field is ENABLED for the current category (filterScopeRules.isFilterCompatible)
                                        - Field has Filter = true (is_filterable) - enforced by backend filterable_schema
                                        - Field has ≥1 value in current asset grid (filterVisibilityRules.hasAvailableValues)
                                        
                                        Phase H helpers used:
                                        - normalizeFilterConfig: Normalizes Inertia props
                                        - filterTierResolver.getSecondaryFilters: Gets metadata fields from schema (excludes is_primary === true)
                                        - filterVisibilityRules.getVisibleFilters: Filters to visible only
                                        
                                        UI behavior:
                                        - Bar always persists (content changes based on category)
                                        - Shows "More filters" button always (disabled if no filters)
                                        - Updates URL query params immediately on change
                                        - Triggers grid refresh (only: ['assets'])
                                        - Shows empty state if no filters available for current category
                                        
                                        Explicitly does NOT render:
                                        - Category selectors (sidebar handles this)
                                        - Asset type selectors (route/nav handles this)
                                        - Brand selectors (never selectable)
                                        - Primary metadata filters (is_primary === true) - handled by AssetGridMetadataPrimaryFilters
                                    */
                                    <AssetGridSecondaryFilters
                                        filterable_schema={filterable_schema}
                                        selectedCategoryId={selectedCategoryId}
                                        available_values={available_values}
                                        canManageFields={(auth?.permissions || []).includes('manage categories') || ['admin', 'owner'].includes(auth?.tenant_role?.toLowerCase() || '')}
                                        assetType="image"
                                        primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                        sortBy={sort}
                                        sortDirection={sort_direction}
                                        onSortChange={(newSort, newDir) => {
                                            const urlParams = new URLSearchParams(window.location.search)
                                            urlParams.set('sort', newSort)
                                            urlParams.set('sort_direction', newDir)
                                            urlParams.delete('page')
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'sort', 'sort_direction'] })
                                        }}
                                        assetResultCount={visibleItems?.length ?? 0}
                                        totalInCategory={localAssets?.length ?? 0}
                                        barTrailingContent={
                                            localAssets?.length > 0 ? (
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={handleSelectAllToggle}
                                                        className="px-2 py-1 text-xs font-medium text-gray-600 bg-white border border-gray-300 rounded hover:bg-gray-50"
                                                    >
                                                        {allVisibleInBucket ? 'Deselect all' : 'Select all'}
                                                    </button>
                                                </div>
                                            ) : null
                                        }
                                    />
                                }
                            />
                        </div>
                        
                        {/* Executions Grid or Empty State */}
                        {localAssets && localAssets.length > 0 ? (
                            <>
                            <AssetGrid 
                                assets={visibleItems} 
                                onAssetClick={(asset) => setActiveAssetId(asset?.id || null)}
                                cardSize={cardSize}
                                showInfo={showInfo}
                                selectedAssetId={activeAssetId}
                                primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                bucketAssetIds={bucketAssetIds}
                                onBucketToggle={handleBucketToggle}
                            />
                            <LoadMoreFooter onLoadMore={loadMore} hasMore={hasMore} />
                            </>
                        ) : (
                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                <div className="mb-8">
                                    <SparklesIcon className="mx-auto h-16 w-16 text-gray-300" />
                                </div>
                                <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                    {searchQuery?.trim()
                                        ? 'No results for this search'
                                        : selectedCategoryId
                                            ? `No ${DELIVERABLES_ITEM_LABEL_PLURAL} in this category yet`
                                            : `No ${DELIVERABLES_ITEM_LABEL_PLURAL} yet`}
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    {searchQuery?.trim()
                                        ? 'Try different keywords or clear the search to see all executions.'
                                        : selectedCategoryId
                                            ? `Get started by uploading your first ${DELIVERABLES_ITEM_LABEL} to this category. Manage your brand assets with ease and keep everything organized.`
                                            : `Get started by selecting a category or uploading your first ${DELIVERABLES_ITEM_LABEL}. Manage your brand assets with ease and keep everything in sync.`}
                                </p>
                                <div className="mt-8">
                                    <AddAssetButton 
                                        defaultAssetType="deliverable" 
                                        onClick={handleOpenUploadDialog}
                                    />
                                </div>
                            </div>
                        )}
                        </div>
                    </div>

                    {/* Asset Drawer - Desktop (pushes grid) */}
                    {activeAsset && (
                        <div className="hidden md:block absolute right-0 top-0 bottom-0 z-50">
                            <AssetDrawer
                                asset={activeAsset}
                                onClose={() => setActiveAssetId(null)}
                                assets={localAssets}
                                currentAssetIndex={localAssets.findIndex(a => a.id === activeAsset.id)}
                                onAssetUpdate={handleLifecycleUpdate}
                                bucketAssetIds={bucketAssetIds}
                                onBucketToggle={handleBucketToggle}
                                primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                            />
                        </div>
                    )}

                    {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on category change */}
                </div>

                {/* Asset Drawer - Mobile (full-width overlay) */}
                {/* CRITICAL: Drawer identity is based ONLY on activeAssetId */}
                {/* Drawer must tolerate temporary undefined asset object during async updates */}
                {/* Only render drawer if activeAssetId is set - asset object may be temporarily undefined */}
                {activeAssetId && (
                    <div className="md:hidden fixed inset-0 z-50">
                        <div className="absolute inset-0 bg-black/50" onClick={() => setActiveAssetId(null)} aria-hidden="true" />
                        <AssetDrawer
                            key={activeAssetId} // Key by ID only - prevents remount on asset object changes
                            asset={activeAsset} // May be undefined temporarily during async updates
                            onClose={() => setActiveAssetId(null)}
                            assets={localAssets}
                            currentAssetIndex={activeAsset ? localAssets.findIndex(a => a.id === activeAsset.id) : -1}
                            onAssetUpdate={handleLifecycleUpdate}
                            bucketAssetIds={bucketAssetIds}
                            onBucketToggle={handleBucketToggle}
                            primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                        />
                    </div>
                )}
            </div>
            
            {/* Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
                Do not convert back to prop-based visibility. */}
            {isUploadDialogOpen && (
                <UploadAssetDialog
                    open={true}
                    onClose={handleCloseUploadDialog}
                    defaultAssetType="deliverable"
                    categories={categories || []}
                    initialCategoryId={selectedCategoryId}
                    onFinalizeComplete={handleFinalizeComplete}
                    initialFiles={droppedFiles}
                />
            )}
        </div>
    )
}
