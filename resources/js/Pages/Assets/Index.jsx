import { useState, useEffect, useRef, useCallback } from 'react'
import { usePage, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AddAssetButton from '../../Components/AddAssetButton'
import UploadAssetDialog from '../../Components/UploadAssetDialog'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetFilters from '../../Components/AssetFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import { mergeAsset, warnIfOverwritingCompletedThumbnail } from '../../utils/assetUtils'
import { useAssetReconciliation } from '../../hooks/useAssetReconciliation'
import { useThumbnailSmartPoll } from '../../hooks/useThumbnailSmartPoll'
import { filterActiveCategories } from '../../utils/categoryUtils'
import {
    FolderIcon,
    TagIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

export default function AssetsIndex({ categories, categories_by_type, selected_category, show_all_button = false, total_asset_count = 0, assets = [], filterable_schema = [], saved_views = [] }) {
    const pageProps = usePage().props
    const { auth } = pageProps
    
    const [selectedCategoryId, setSelectedCategoryId] = useState(selected_category ? parseInt(selected_category) : null)
    const [tooltipVisible, setTooltipVisible] = useState(null)
    
    // FINAL FIX: Remount key to force page remount after finalize
    const [remountKey, setRemountKey] = useState(0)
    
    // Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
    // Do not convert back to prop-based visibility.
    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false)
    
    // Prevent reopening dialog during auto-close timeout (400-700ms delay)
    const [isAutoClosing, setIsAutoClosing] = useState(false)
    
    // Local asset state
    const [localAssets, setLocalAssets] = useState(assets)
    
    // Update local assets when props change (e.g., after Inertia reload)
    // Guard: Protect completed thumbnails from being overwritten on refresh
    useEffect(() => {
        setLocalAssets(prevAssets => {
            // If no previous assets, use new assets as-is
            if (!prevAssets || prevAssets.length === 0) {
                return assets
            }
            
            // Merge new assets with field-level protection
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
    }, [assets])
    
    // Store only asset ID to prevent stale object references after Inertia reloads
    // The active asset is derived from the current assets array, ensuring it always reflects fresh data
    const [activeAssetId, setActiveAssetId] = useState(null) // Asset ID selected for drawer
    
    // Phase 2 – Step 7: Bulk selection state
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])
    const [isBulkMode, setIsBulkMode] = useState(false)
    const [showBulkEditModal, setShowBulkEditModal] = useState(false)
    
    // Derive active asset from local assets array to prevent stale references
    // If asset no longer exists after reload, activeAsset will be null and drawer will close
    const activeAsset = activeAssetId ? localAssets.find(asset => asset.id === activeAssetId) : null
    
    // Close drawer if active asset no longer exists in current assets array
    useEffect(() => {
        if (activeAssetId && !activeAsset) {
            setActiveAssetId(null)
        }
    }, [activeAssetId, activeAsset, localAssets])

    // Category switches should reset the drawer selection,
    // but must NOT remount the entire page (that destroys <img> nodes and causes flashes).
    useEffect(() => {
        setActiveAssetId(null)
    }, [selectedCategoryId])
    
    // HARD STABILIZATION: Background reconciliation disabled
    // Assets only change when Inertia provides a new snapshot.
    // NOTE: Thumbnails intentionally do NOT live-update on the grid.
    // Stability > real-time updates.
    // useAssetReconciliation({
    //     assets: localAssets,
    //     selectedCategoryId,
    //     isPaused: isUploadDialogOpen,
    // })
    
    // HARD STABILIZATION: Thumbnail polling disabled
    // NOTE: Thumbnails intentionally do NOT live-update on the grid.
    // Stability > real-time updates.
    // Live thumbnail upgrades can be reintroduced later via explicit user action (refresh / reopen page).
    // const handleThumbnailUpdate = useCallback((updatedAsset) => {
    //     setLocalAssets(prevAssets => {
    //         return prevAssets.map(asset => {
    //             if (asset.id === updatedAsset.id) {
    //                 // Merge updated asset data
    //                 return mergeAsset(asset, updatedAsset)
    //             }
    //             return asset
    //         })
    //     })
    // }, [])
    // 
    // useThumbnailSmartPoll({
    //     assets: localAssets,
    //     onAssetUpdate: handleThumbnailUpdate,
    //     selectedCategoryId,
    // })
    
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

    // Handle category selection - triggers Inertia reload with slug-based category query param (?category=rarr)
    const handleCategorySelect = (category) => {
        const categoryId = category?.id ?? category // Support both object and ID for backward compatibility
        const categorySlug = category?.slug ?? null
        
        // Phase 2 invariant: Explicitly reset dialog state before preserveState navigation
        // This prevents Inertia from preserving isUploadDialogOpen=true across category changes
        setIsUploadDialogOpen(false)
        
        setSelectedCategoryId(categoryId)
        
        router.get('/app/assets', 
            categorySlug ? { category: categorySlug } : {},
            { 
                preserveState: true, 
                preserveScroll: true,
                only: ['assets', 'selected_category', 'selected_category_slug'] // Only reload assets and category props
            }
        )
    }

    // Handle finalize complete - refresh asset grid after successful upload finalize
    const handleFinalizeComplete = useCallback(() => {
        // Set auto-closing flag to prevent reopening during timeout
        setIsAutoClosing(true)
        
        // Ensure dialog stays closed during reload
        setIsUploadDialogOpen(false)
        
        // Force page remount by incrementing remount key
        setRemountKey(prev => prev + 1)
        
        // Reload assets to show newly uploaded assets
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
        e.preventDefault()
        e.stopPropagation()
        // Only show drag overlay if dragging files (not other elements)
        if (e.dataTransfer.types.includes('Files')) {
            setIsDraggingOver(true)
        }
    }, [])
    
    const handleDragEnter = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        // Only show drag overlay if dragging files (not other elements)
        if (e.dataTransfer.types.includes('Files')) {
            setIsDraggingOver(true)
        }
    }, [])
    
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
    }, [handleOpenUploadDialog])

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
    

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-72 h-full" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                            <nav className="mt-5 flex-1 px-2 space-y-1">
                                {/* Add Asset Button - Persistent in sidebar (only show if user has upload permissions) */}
                                {auth?.user && (
                                    <div className="px-3 py-2 mb-4">
                                        <AddAssetButton 
                                            defaultAssetType="asset" 
                                            className="w-full"
                                            onClick={handleOpenUploadDialog}
                                            disabled={isAutoClosing}
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
                                                    backgroundColor: selectedCategoryId === null || selectedCategoryId === undefined ? activeBgColor : 'transparent',
                                                    color: textColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (selectedCategoryId !== null && selectedCategoryId !== undefined) {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (selectedCategoryId !== null && selectedCategoryId !== undefined) {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                    }
                                                }}
                                            >
                                                <TagIcon className="mr-3 flex-shrink-0 h-5 w-5" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }} />
                                                <span className="flex-1">All</span>
                                                {total_asset_count > 0 && (
                                                    <span className="text-xs font-normal opacity-50" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.4)' : 'rgba(0, 0, 0, 0.4)' }}>
                                                        {total_asset_count}
                                                    </span>
                                                )}
                                            </button>
                                        )}
                                        {/* Show all categories (both basic and marketing) in a single list */}
                                        {categories && categories.length > 0 ? (
                                            filterActiveCategories(categories)
                                                .map((category) => {
                                                    const isSelected = selectedCategoryId === category.id && selectedCategoryId !== null && selectedCategoryId !== undefined
                                                    return (
                                                    <button
                                                        key={category.id}
                                                        onClick={() => handleCategorySelect(category)}
                                                        className="group flex items-center px-3 py-2 text-sm font-medium rounded-md w-full text-left"
                                                        style={{
                                                            backgroundColor: isSelected ? activeBgColor : 'transparent',
                                                            color: textColor,
                                                        }}
                                                        onMouseEnter={(e) => {
                                                            if (!isSelected) {
                                                                e.currentTarget.style.backgroundColor = hoverBgColor
                                                            }
                                                        }}
                                                        onMouseLeave={(e) => {
                                                            if (!isSelected) {
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
                                                        {category.asset_count !== undefined && category.asset_count > 0 && (
                                                            <span className="text-xs font-normal opacity-50 ml-2" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.4)' : 'rgba(0, 0, 0, 0.4)' }}>
                                                                {category.asset_count}
                                                            </span>
                                                        )}
                                                        {category.is_private && (
                                                            <div className="relative ml-2 group">
                                                                <LockClosedIcon 
                                                                    className="h-4 w-4 flex-shrink-0 cursor-help" 
                                                                    style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}
                                                                    onMouseEnter={() => setTooltipVisible(category.id)}
                                                                    onMouseLeave={() => setTooltipVisible(null)}
                                                                />
                                                                {tooltipVisible === category.id && (
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
                                                    )
                                                })
                                        ) : (
                                            <div className="px-3 py-2 text-sm" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                                No categories yet
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </nav>
                        </div>
                    </div>
                </div>

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative">
                    <div 
                        className="h-full overflow-y-auto transition-[padding-right] duration-300 ease-in-out relative"
                        style={{ 
                            // Freeze grid layout during drawer animation to prevent mid-animation reflow
                            // CSS Grid recalculates columns immediately on width change
                            // By delaying padding change until after animation, we get one controlled snap instead of dropping items mid-animation
                            // Use isDrawerOpen (not activeAsset) to prevent layout changes on asset swaps
                            paddingRight: (isDrawerOpen && !isDrawerAnimating) ? '480px' : '0' 
                        }}
                        onDragOver={handleDragOver}
                        onDragEnter={handleDragEnter}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
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
                        {/* Asset Grid Toolbar */}
                        {localAssets && localAssets.length > 0 && (
                            <div className="mb-6 space-y-4">
                                <AssetGridToolbar
                                    showInfo={showInfo}
                                    onToggleInfo={() => setShowInfo(v => !v)}
                                    cardSize={cardSize}
                                    onCardSizeChange={setCardSize}
                                    primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                    bulkSelectedCount={bulkSelectedAssetIds.length}
                                    onBulkEdit={() => {
                                        if (bulkSelectedAssetIds.length > 0) {
                                            setShowBulkEditModal(true)
                                        }
                                    }}
                                    onToggleBulkMode={() => {
                                        setIsBulkMode((prev) => !prev)
                                        if (isBulkMode) {
                                            setBulkSelectedAssetIds([])
                                        }
                                    }}
                                    isBulkMode={isBulkMode}
                                />
                                
                                {/* Phase 2 – Step 8: Metadata Filters */}
                                {/* 
                                    Only render AssetFilters when:
                                    - A specific category is selected (not "All" / selectedCategoryId is truthy)
                                    - filterable_schema exists and has fields
                                    
                                    When "All" is selected (selectedCategoryId is null/undefined), 
                                    metadata schema cannot be resolved, so filters are not available.
                                */}
                                {selectedCategoryId ? (
                                    filterable_schema && filterable_schema.length > 0 ? (
                                        <div className="flex items-center justify-end">
                                            <AssetFilters
                                                filterableSchema={filterable_schema}
                                                categoryId={selectedCategoryId}
                                                savedViews={saved_views}
                                            />
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-end">
                                            <span className="text-xs text-gray-500 italic">
                                                No filterable metadata available for this category
                                            </span>
                                        </div>
                                    )
                                ) : (
                                    <div className="flex items-center justify-end">
                                        <span className="text-xs text-gray-500 italic">
                                            Select a category to filter by metadata
                                        </span>
                                    </div>
                                )}
                            </div>
                        )}
                            
                            {/* Assets Grid or Empty State */}
                            {localAssets && localAssets.length > 0 ? (
                                <AssetGrid 
                                    assets={localAssets} 
                                    onAssetClick={(asset) => {
                                        if (!isBulkMode) {
                                            setActiveAssetId(asset?.id || null)
                                        }
                                    }}
                                    cardSize={cardSize}
                                    showInfo={showInfo}
                                    selectedAssetId={activeAssetId}
                                    primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                    selectedAssetIds={isBulkMode ? bulkSelectedAssetIds : []}
                                    onAssetSelect={isBulkMode ? ((assetId) => {
                                        setBulkSelectedAssetIds((prev) =>
                                            prev.includes(assetId)
                                                ? prev.filter((id) => id !== assetId)
                                                : [...prev, assetId]
                                        )
                                    }) : null}
                                />
                            ) : (
                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                <div className="mb-8">
                                    <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                                </div>
                                <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                    {selectedCategoryId ? 'No assets in this category yet' : 'No assets yet'}
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    {selectedCategoryId
                                        ? 'Get started by uploading your first asset to this category. Organize your brand assets and keep everything in one place.'
                                        : 'Get started by selecting a category or uploading your first asset. Organize your brand assets and keep everything in sync.'}
                                </p>
                                <div className="mt-8">
                                    <AddAssetButton 
                                        defaultAssetType="asset" 
                                        onClick={handleOpenUploadDialog}
                                        disabled={isAutoClosing}
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
                            />
                        </div>
                    )}
                </div>

                {/* Asset Drawer - Mobile (full-width overlay) */}
                {activeAsset && (
                    <div className="md:hidden fixed inset-0 z-50">
                        <div className="absolute inset-0 bg-black/50" onClick={() => setActiveAssetId(null)} aria-hidden="true" />
                        <AssetDrawer
                            asset={activeAsset}
                            onClose={() => setActiveAssetId(null)}
                            assets={localAssets}
                            currentAssetIndex={localAssets.findIndex(a => a.id === activeAsset.id)}
                        />
                    </div>
                )}
            </div>
            
            {/* Phase 2 – Step 7: Bulk Metadata Edit Modal */}
            {showBulkEditModal && bulkSelectedAssetIds.length > 0 && (
                <BulkMetadataEditModal
                    assetIds={bulkSelectedAssetIds}
                    onClose={() => {
                        setShowBulkEditModal(false)
                    }}
                    onComplete={() => {
                        // Refresh assets after bulk edit
                        router.reload({ only: ['assets'] })
                        setBulkSelectedAssetIds([])
                        setIsBulkMode(false)
                    }}
                />
            )}

            {/* Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
                Do not convert back to prop-based visibility. */}
            {isUploadDialogOpen && (
                <UploadAssetDialog
                    open={true}
                    onClose={handleCloseUploadDialog}
                    defaultAssetType="asset"
                    categories={categories || []}
                    initialCategoryId={selectedCategoryId}
                    onFinalizeComplete={handleFinalizeComplete}
                    initialFiles={droppedFiles}
                />
            )}
        </div>
    )
}
