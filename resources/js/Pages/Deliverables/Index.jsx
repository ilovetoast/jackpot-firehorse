import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import { usePage, router } from '@inertiajs/react'
import { useAssetReconciliation } from '../../hooks/useAssetReconciliation'
import { useThumbnailSmartPoll } from '../../hooks/useThumbnailSmartPoll'
import { usePermission } from '../../hooks/usePermission'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import OnlineUsersIndicator from '../../Components/OnlineUsersIndicator'
import axios from 'axios'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AddAssetButton from '../../Components/AddAssetButton'
import UploadAssetDialog from '../../Components/UploadAssetDialog'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkActionsModal, { computeSelectionSummary } from '../../Components/BulkActionsModal'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import SelectionActionBar from '../../Components/SelectionActionBar'
import { useSelection } from '../../contexts/SelectionContext'
import { useBucketOptional } from '../../contexts/BucketContext'
import { mergeAsset, warnIfOverwritingCompletedThumbnail } from '../../utils/assetUtils'
import { DELIVERABLES_ITEM_LABEL, DELIVERABLES_ITEM_LABEL_PLURAL } from '../../utils/uiLabels'
import { getWorkspaceButtonColor, getContrastTextColor, darkenColor } from '../../utils/colorUtils'
import {
    TagIcon,
    SparklesIcon,
    LockClosedIcon,
    TrashIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

export default function DeliverablesIndex({ categories, total_asset_count = 0, selected_category, show_all_button = false, assets = [], next_page_url = null, filterable_schema = [], available_values = {}, sort = 'created', sort_direction = 'desc', compliance_filter = '', show_compliance_filter = false, q: searchQuery = '', lifecycle = '', can_view_trash = false, trash_count = 0 }) {
    const pageProps = usePage().props
    const { auth } = pageProps
    const { can } = usePermission()
    const canUpload = can('asset.upload')
    
    const [selectedCategoryId, setSelectedCategoryId] = useState(selected_category ? parseInt(selected_category) : null)
    const [tooltipVisible, setTooltipVisible] = useState(null)
    
    // FINAL FIX: Remount key to force page remount after finalize
    const [remountKey, setRemountKey] = useState(0)
    
    // Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
    // Do not convert back to prop-based visibility.
    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false)
    
    // Prevent reopening dialog during auto-close timeout (400-700ms delay)
    const [isAutoClosing, setIsAutoClosing] = useState(false)
    
    // Server-driven pagination
    const [assetsList, setAssetsList] = useState(Array.isArray(assets) ? assets.filter(Boolean) : [])
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loading, setLoading] = useState(false)
    const loadMoreRef = useRef(null)

    useEffect(() => {
        const list = Array.isArray(assets) ? assets.filter(Boolean) : []
        setAssetsList(list)
        setNextPageUrl(next_page_url ?? null)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', { detail: { hasStaleAssetGrid: false } }))
        }
        // Grid timing: log navigation-to-first-render (staging diagnostic)
        if (list.length > 0 && typeof window !== 'undefined' && window.__inertiaVisitStart != null) {
            const ms = Math.round(performance.now() - window.__inertiaVisitStart)
            console.info('[DELIVERABLE_GRID_TIMING] navigation to first grid render', { ms, assetCount: list.length })
        }
    }, [assets, next_page_url])

    const loadMore = useCallback(async () => {
        if (!nextPageUrl || loading) return
        setLoading(true)
        try {
            const separator = nextPageUrl.includes('?') ? '&' : '?'
            const url = nextPageUrl + separator + 'load_more=1'
            const response = await axios.get(url)
            const data = response.data?.data ?? []
            setAssetsList(prev => [...(prev || []).filter(Boolean), ...(Array.isArray(data) ? data.filter(Boolean) : [])])
            setNextPageUrl(response.data?.next_page_url ?? null)
        } catch (e) {
            console.error('Infinite scroll failed', e)
        } finally {
            setLoading(false)
        }
    }, [nextPageUrl, loading])

    useEffect(() => {
        if (!loadMoreRef.current) return
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting && nextPageUrl && !loading) loadMore()
            },
            { rootMargin: '200px' }
        )
        observer.observe(loadMoreRef.current)
        return () => observer.disconnect()
    }, [nextPageUrl, loading, loadMore])
    
    // Store only asset ID to prevent stale object references after Inertia reloads
    // The active asset is derived from the current assets array, ensuring it always reflects fresh data
    const [activeAssetId, setActiveAssetId] = useState(null) // Asset ID selected for drawer
    const [showBulkActionsModal, setShowBulkActionsModal] = useState(false)
    const [showBulkMetadataModal, setShowBulkMetadataModal] = useState(false)
    const [bulkMetadataInitialOp, setBulkMetadataInitialOp] = useState(null)
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])
    const userClosedDrawerRef = useRef(false)
    const lastOpenedFromUrlRef = useRef(null)
    
    // Derive active asset from local assets array to prevent stale references
    // CRITICAL: Drawer identity is based ONLY on activeAssetId, not asset object identity
    // Asset object mutations (async updates, thumbnail swaps, etc.) must NOT close the drawer
    const safeAssetsList = (assetsList || []).filter(Boolean)
    const activeAsset = activeAssetId ? safeAssetsList.find(asset => asset?.id === activeAssetId) : null
    
    // Close drawer ONLY if active asset ID truly doesn't exist in current assets array
    useEffect(() => {
        if (activeAssetId) {
            const assetExists = safeAssetsList.some(asset => asset?.id === activeAssetId)
            if (!assetExists) {
                // Asset ID no longer exists in array - close drawer
                setActiveAssetId(null)
            }
        }
    }, [activeAssetId, safeAssetsList])

    const { selectedCount, clearSelection, getSelectedOnPage } = useSelection()
    const bucket = useBucketOptional()
    const bucketAssetIds = bucket?.bucketAssetIds ?? []
    const handleBucketToggle = useCallback((assetId) => {
        if (!bucket) return
        if (bucketAssetIds.includes(assetId)) {
            bucket.bucketRemove(assetId)
        } else {
            bucket.bucketAdd(assetId)
        }
    }, [bucket, bucketAssetIds])

    // Category switches should reset the drawer selection
    // but must NOT remount the entire page (that destroys <img> nodes and causes flashes).
    // Match Assets/Index behavior: don't clear localAssets immediately - let the assets
    // useEffect handle category changes by replacing (not merging) when category changed.
    useEffect(() => {
        setActiveAssetId(null)
        userClosedDrawerRef.current = false
        lastOpenedFromUrlRef.current = null
        
        // Clear staleness flag when category changes (view is synced with new category)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
    }, [selectedCategoryId])
    
    // Open drawer from URL query parameter (e.g., ?asset={id}&edit_metadata={field_id})
    // Also clear staleness flag on mount (navigation to /app/executions completes)
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
            
            if (assetId && safeAssetsList.length > 0) {
                const asset = safeAssetsList.find(a => a?.id === assetId)
                if (asset) {
                    if (userClosedDrawerRef.current && assetId === lastOpenedFromUrlRef.current) return
                    setActiveAssetId(assetId)
                    lastOpenedFromUrlRef.current = assetId
                    userClosedDrawerRef.current = false
                }
            }
        }
    }, [safeAssetsList])

    // useThumbnailSmartPoll updates assetsList in-place via handleThumbnailUpdate callback
    // This matches Assets/Index behavior - reconciliation is disabled there too
    // useAssetReconciliation({
    //     assets,
    //     selectedCategoryId,
    //     isPaused: isUploadDialogOpen,
    // })
    
    // Grid thumbnail polling: Async updates for fade-in (same as drawer)
    // No view refreshes - only local state updates
    const handleThumbnailUpdate = useCallback((updatedAsset) => {
        setAssetsList(prevAssets => {
            return (prevAssets || []).filter(Boolean).map(asset => {
                if (asset?.id === updatedAsset?.id) {
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
        setAssetsList(prevAssets => {
            return (prevAssets || []).filter(Boolean).map(asset => {
                if (asset?.id === updatedAsset?.id) {
                    // Merge updated asset data (preserves thumbnail state, updates lifecycle fields)
                    return mergeAsset(asset, updatedAsset)
                }
                return asset
            })
        })
    }, [])
    
    useThumbnailSmartPoll({
        assets: safeAssetsList,
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
        const parsed = stored ? parseInt(stored, 10) : 220
        return [160, 220, 280, 360].includes(parsed) ? parsed : 220
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
        
        router.get('/app/executions', 
            categorySlug ? { category: categorySlug } : {},
            { 
                preserveState: true, 
                preserveScroll: true,
                only: ['filterable_schema', 'available_values', 'assets', 'next_page_url', 'selected_category', 'selected_category_slug', 'compliance_filter', 'show_compliance_filter', 'lifecycle', 'trash_count', 'can_view_trash']
            }
        )
    }

    // Get brand sidebar color (nav_color) for sidebar background, fallback to primary color
    const sidebarColor = auth.activeBrand?.nav_color || auth.activeBrand?.primary_color || '#1f2937' // Default to gray-800 if no brand color
    const workspaceAccentColor = getWorkspaceButtonColor(auth.activeBrand)
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
    // Match Add Execution button: selected/hover use dark hue (same as button hover state)
    const contextualDarkColor = darkenColor(workspaceAccentColor, 20)
    const activeBgColor = contextualDarkColor
    const activeTextColor = getContrastTextColor(contextualDarkColor)
    const hoverBgColor = contextualDarkColor
    // Unselected: reduced opacity for visual hierarchy (matches icon treatment)
    const unselectedTextColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.65)' : 'rgba(0, 0, 0, 0.65)'
    const unselectedIconColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)'
    const unselectedCountColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)'

    const visibleMobileCategories = useMemo(() => (
        categories.filter((category) => !(
            category.is_hidden === true ||
            category.is_hidden === 1 ||
            category.is_hidden === '1' ||
            category.is_hidden === 'true'
        ))
    ), [categories])

    const mobileCategoryTabs = useMemo(() => {
        const tabs = []

        if (show_all_button) {
            tabs.push({
                key: 'all',
                label: 'All',
                count: total_asset_count > 0 ? total_asset_count : null,
                category: null,
                categoryId: null,
            })
        }

        visibleMobileCategories.forEach((category) => {
            tabs.push({
                key: String(category.id ?? `template-${category.slug}-${category.asset_type}`),
                label: category.name,
                count: category.asset_count > 0 ? category.asset_count : null,
                category,
                categoryId: category.id ?? null,
            })
        })

        return tabs
    }, [show_all_button, total_asset_count, visibleMobileCategories])

    const activeMobileCategoryTabIndex = useMemo(() => (
        mobileCategoryTabs.findIndex((tab) => {
            if (tab.categoryId == null && selectedCategoryId == null) {
                return true
            }

            if (tab.categoryId == null || selectedCategoryId == null) {
                return false
            }

            return String(tab.categoryId) === String(selectedCategoryId)
        })
    ), [mobileCategoryTabs, selectedCategoryId])

    const safeActiveMobileCategoryTabIndex = activeMobileCategoryTabIndex >= 0
        ? activeMobileCategoryTabIndex
        : (mobileCategoryTabs.length > 0 ? 0 : -1)

    const activeTabRef = useRef(null)
    useEffect(() => {
        activeTabRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' })
    }, [safeActiveMobileCategoryTabIndex])

    const handleMobileCategoryTabChange = useCallback((targetIndex) => {
        if (targetIndex < 0 || targetIndex >= mobileCategoryTabs.length) {
            return
        }

        const currentIndex = activeMobileCategoryTabIndex
        if (currentIndex >= 0 && currentIndex === targetIndex) {
            return
        }

        const targetTab = mobileCategoryTabs[targetIndex]
        if (!targetTab) {
            return
        }

        handleCategorySelect(targetTab.category)
    }, [mobileCategoryTabs, activeMobileCategoryTabIndex, handleCategorySelect])

    // Framer Motion swipe: Instagram-style full-frame drag with velocity-aware control
    const SWIPE_THRESHOLD = 80
    const SWIPE_VELOCITY_THRESHOLD = 250
    const [viewportWidth, setViewportWidth] = useState(400)
    useEffect(() => {
        const update = () => setViewportWidth(window.innerWidth)
        update()
        window.addEventListener('resize', update)
        return () => window.removeEventListener('resize', update)
    }, [])
    const dragConstraint = Math.min(viewportWidth * 0.5, 200)

    const [dragOffsetX, setDragOffsetX] = useState(0)
    const [tabsCanScrollRight, setTabsCanScrollRight] = useState(false)
    const tabsScrollRef = useRef(null)
    const updateTabsScrollState = useCallback(() => {
        const el = tabsScrollRef.current
        if (!el) return
        const { scrollLeft, scrollWidth, clientWidth } = el
        setTabsCanScrollRight(scrollLeft + clientWidth < scrollWidth - 2)
    }, [])
    useEffect(() => {
        const el = tabsScrollRef.current
        if (!el) return
        updateTabsScrollState()
        el.addEventListener('scroll', updateTabsScrollState)
        const ro = new ResizeObserver(updateTabsScrollState)
        ro.observe(el)
        return () => {
            el.removeEventListener('scroll', updateTabsScrollState)
            ro.disconnect()
        }
    }, [updateTabsScrollState, mobileCategoryTabs.length])

    const handleSwipeDragEnd = useCallback((_, info) => {
        setDragOffsetX(0)
        const { offset, velocity } = info
        const vx = velocity.x
        const ox = offset.x
        const nextIndex = safeActiveMobileCategoryTabIndex + 1
        const prevIndex = safeActiveMobileCategoryTabIndex - 1

        if (ox < -SWIPE_THRESHOLD || vx < -SWIPE_VELOCITY_THRESHOLD) {
            handleMobileCategoryTabChange(nextIndex)
        } else if (ox > SWIPE_THRESHOLD || vx > SWIPE_VELOCITY_THRESHOLD) {
            handleMobileCategoryTabChange(prevIndex)
        }
    }, [safeActiveMobileCategoryTabIndex, handleMobileCategoryTabChange])

    
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
            // Filter to supported DAM file types (images, video, PDF, PSD, AI, SVG, Office, etc.)
            const supportedMimes = [
                'image/', 'video/', 'application/pdf', 'application/postscript',
                'application/vnd.adobe.illustrator', 'application/illustrator',
                'image/vnd.adobe.photoshop', 'image/svg+xml',
                'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.',
            ]
            const supportedExts = ['ai', 'psd', 'psb', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']
            const isSupported = (f) => {
                if (supportedMimes.some(m => f.type && (f.type.startsWith(m) || f.type === m))) return true
                const ext = (f.name || '').split('.').pop()?.toLowerCase()
                return ext && supportedExts.includes(ext)
            }
            const imageFiles = files.filter(isSupported)
            if (imageFiles.length > 0) {
                handleOpenUploadDialog(imageFiles)
            }
        }
    }, [canUpload, handleOpenUploadDialog])

    return (
        <div key={pageKey} className="h-screen flex flex-col overflow-hidden" data-category-id={selectedCategoryId ?? 'all'}>
            <AppHead title="Deliverables" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-64 xl:w-72 h-full transition-[width] duration-200" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-4 pb-3 lg:pt-5 lg:pb-4 overflow-y-auto">
                            <nav className="mt-3 lg:mt-5 flex-1 px-1.5 lg:px-2 space-y-1">
                                {/* Add Execution Button - Persistent in sidebar (only show if user has upload permissions) */}
                                {auth?.user && (
                                    <div className="px-2 py-1.5 lg:px-3 lg:py-2 mb-3 lg:mb-4">
                                        <AddAssetButton 
                                            defaultAssetType="deliverable" 
                                            className="w-full"
                                            onClick={handleOpenUploadDialog}
                                        />
                                    </div>
                                )}
                                
                                {/* Categories */}
                                <div className="px-2 py-1.5 lg:px-3 lg:py-2">
                                    <h3 className="px-2 lg:px-3 text-xs font-semibold uppercase tracking-wider" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                        Categories
                                    </h3>
                                    <div className="mt-1.5 lg:mt-2 space-y-1">
                                        {/* "All" button - only shown for non-free plans */}
                                        {show_all_button && (
                                            <button
                                                onClick={() => handleCategorySelect(null)}
                                                className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: (selectedCategoryId === null && lifecycle !== 'deleted') ? activeBgColor : 'transparent',
                                                    color: (selectedCategoryId === null && lifecycle !== 'deleted') ? activeTextColor : unselectedTextColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (selectedCategoryId !== null || lifecycle === 'deleted') {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                        e.currentTarget.style.color = activeTextColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (selectedCategoryId !== null || lifecycle === 'deleted') {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                        e.currentTarget.style.color = unselectedTextColor
                                                    }
                                                }}
                                                >
                                                <TagIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: (selectedCategoryId === null && lifecycle !== 'deleted') ? activeTextColor : unselectedIconColor }} />
                                                <span className="flex-1">All</span>
                                                {total_asset_count > 0 && (
                                                    <span className="text-xs font-normal opacity-80" style={{ color: (selectedCategoryId === null && lifecycle !== 'deleted') ? activeTextColor : unselectedCountColor }}>
                                                        {total_asset_count}
                                                    </span>
                                                )}
                                            </button>
                                        )}
                                        {categories.length > 0 ? (
                                            <>
                                            {categories
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
                                                    className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                    style={{
                                                        backgroundColor: selectedCategoryId === category.id ? activeBgColor : 'transparent',
                                                        color: selectedCategoryId === category.id ? activeTextColor : unselectedTextColor,
                                                    }}
                                                    onMouseEnter={(e) => {
                                                        if (selectedCategoryId !== category.id) {
                                                            e.currentTarget.style.backgroundColor = hoverBgColor
                                                            e.currentTarget.style.color = activeTextColor
                                                        }
                                                    }}
                                                    onMouseLeave={(e) => {
                                                        if (selectedCategoryId !== category.id) {
                                                            e.currentTarget.style.backgroundColor = 'transparent'
                                                            e.currentTarget.style.color = unselectedTextColor
                                                        }
                                                    }}
                                                >
                                                    <CategoryIcon 
                                                        iconId={category.icon || 'folder'} 
                                                        className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" 
                                                        style={{ color: selectedCategoryId === category.id ? activeTextColor : unselectedIconColor }}
                                                    />
                                                    <span className="flex-1">{category.name}</span>
                                                    {category.asset_count !== undefined && category.asset_count > 0 && (
                                                        <span className="text-xs font-normal opacity-80" style={{ color: selectedCategoryId === category.id ? activeTextColor : unselectedCountColor }}>
                                                            {category.asset_count}
                                                        </span>
                                                    )}
                                                    {category.is_private && (
                                                        <div className="relative ml-2 group">
                                                            <LockClosedIcon 
                                                                className="h-4 w-4 flex-shrink-0 cursor-help" 
                                                                style={{ color: selectedCategoryId === category.id ? activeTextColor : unselectedIconColor }}
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
                                            }
                                            {/* Phase B2: Trash at bottom - only when has items or already on trash view */}
                                            {can_view_trash && (trash_count > 0 || lifecycle === 'deleted') && (
                                                <>
                                                    <div className="my-1.5 border-t" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.12)' }} />
                                                    <button
                                                        onClick={() => router.get('/app/executions', { lifecycle: 'deleted' })}
                                                        className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                        style={{
                                                            backgroundColor: lifecycle === 'deleted' ? activeBgColor : 'transparent',
                                                            color: lifecycle === 'deleted' ? activeTextColor : unselectedTextColor,
                                                        }}
                                                        onMouseEnter={(e) => {
                                                            if (lifecycle !== 'deleted') {
                                                                e.currentTarget.style.backgroundColor = hoverBgColor
                                                                e.currentTarget.style.color = activeTextColor
                                                            }
                                                        }}
                                                        onMouseLeave={(e) => {
                                                            if (lifecycle !== 'deleted') {
                                                                e.currentTarget.style.backgroundColor = 'transparent'
                                                                e.currentTarget.style.color = unselectedTextColor
                                                            }
                                                        }}
                                                    >
                                                        <TrashIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: lifecycle === 'deleted' ? activeTextColor : unselectedIconColor }} />
                                                        <span className="flex-1">Trash</span>
                                                        {trash_count > 0 && (
                                                            <span className="text-xs font-normal opacity-80" style={{ color: lifecycle === 'deleted' ? activeTextColor : unselectedCountColor }}>
                                                                {trash_count}
                                                            </span>
                                                        )}
                                                    </button>
                                                </>
                                            )}
                                            </>
                                        ) : (
                                            <div className="px-3 py-2 text-sm" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                                No execution categories yet
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </nav>
                        </div>
                        <div className="flex-shrink-0 px-1.5 lg:px-2 pb-2 lg:pb-3">
                            <OnlineUsersIndicator
                                textColor={textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)'}
                                primaryColor={workspaceAccentColor}
                                isLightBackground={isLightColor(sidebarColor)}
                            />
                        </div>
                    </div>
                </div>

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative flex flex-col">
                    <div className="lg:hidden border-b border-gray-200 bg-gray-100 shrink-0 sticky top-0 z-20">
                        <div className="px-3 sm:px-6 py-2 relative">
                            <div
                                ref={tabsScrollRef}
                                className="executions-category-tabs flex flex-nowrap items-center gap-1 sm:gap-0.5 overflow-x-auto overflow-y-hidden pb-1 -mb-1"
                            >
                                {mobileCategoryTabs.length > 0 ? mobileCategoryTabs.map((tab, index) => {
                                    const isActive = index === safeActiveMobileCategoryTabIndex
                                    return (
                                        <motion.button
                                            key={tab.key}
                                            ref={isActive ? activeTabRef : null}
                                            type="button"
                                            onClick={() => handleMobileCategoryTabChange(index)}
                                            className={`relative shrink-0 px-3 py-2 text-sm font-medium rounded-lg transition-all duration-200 ease-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-100 ${
                                                isActive
                                                    ? 'bg-white text-gray-900 shadow-md ring-1 ring-gray-200/60'
                                                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50/80'
                                            }`}
                                            aria-pressed={isActive}
                                            whileTap={{ scale: 0.98 }}
                                            transition={{ type: 'spring', stiffness: 400, damping: 17 }}
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                {tab.label}
                                                {tab.count != null && tab.count > 0 ? (
                                                    <span className="text-xs opacity-70">({tab.count})</span>
                                                ) : null}
                                            </span>
                                        </motion.button>
                                    )
                                }) : (
                                    <span className="px-3 text-sm text-gray-500">No execution categories yet</span>
                                )}
                            </div>
                            {/* Gradient + chevron indicator when more tabs are off-screen to the right */}
                            {tabsCanScrollRight && mobileCategoryTabs.length > 1 && (
                                <div
                                    className="absolute right-0 top-0 bottom-0 w-12 sm:w-16 pointer-events-none flex items-center justify-end pr-1"
                                    style={{
                                        background: 'linear-gradient(to right, transparent, rgb(243 244 246 / 0.95))',
                                    }}
                                    aria-hidden
                                >
                                    <ChevronRightIcon className="h-5 w-5 text-gray-400" />
                                </div>
                            )}
                        </div>
                    </div>
                    <motion.div
                        className="flex-1 min-h-0 overflow-y-auto overflow-x-hidden transition-[padding-right] duration-300 ease-in-out relative pb-0 touch-pan-y"
                        style={{ 
                            paddingRight: (isDrawerOpen && !isDrawerAnimating) ? '480px' : '0',
                            touchAction: 'pan-y',
                        }}
                        drag={mobileCategoryTabs.length > 1 ? 'x' : false}
                        dragConstraints={{ left: -dragConstraint, right: dragConstraint }}
                        dragElastic={0.12}
                        onDrag={(_, info) => setDragOffsetX(info.offset.x)}
                        onDragEnd={handleSwipeDragEnd}
                        dragTransition={{ bounceStiffness: 400, bounceDamping: 35 }}
                        onDragOver={canUpload ? handleDragOver : undefined}
                        onDragEnter={canUpload ? handleDragEnter : undefined}
                        onDragLeave={canUpload ? handleDragLeave : undefined}
                        onDrop={canUpload ? handleDrop : undefined}
                    >
                        {/* Drag and drop overlay */}
                        {isDraggingOver && (() => {
                            // Ensure color has # prefix, then add 60% opacity (99 in hex = ~60%)
                            const colorWithOpacity = workspaceAccentColor.startsWith('#') 
                                ? `${workspaceAccentColor}99` 
                                : `#${workspaceAccentColor}99`
                            
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
                        <AnimatePresence mode="wait">
                            <motion.div
                                key={selectedCategoryId ?? 'all'}
                                initial={{ opacity: 0 }}
                                animate={{ 
                                    opacity: Math.max(0.55, 1 - Math.min(Math.abs(dragOffsetX) / 100, 0.45)),
                                }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.2, ease: 'easeInOut' }}
                                className="py-6 px-4 sm:px-6 lg:px-8"
                            >
                        {/* Asset Grid Toolbar - Always visible (persists across categories) */}
                        {/* Matches Assets/Index behavior - toolbar always visible, even when no assets */}
                        <div className="mb-8">
                            <AssetGridToolbar
                                showInfo={showInfo}
                                onToggleInfo={() => setShowInfo(v => !v)}
                                cardSize={cardSize}
                                onCardSizeChange={setCardSize}
                                primaryColor={workspaceAccentColor}
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
                                        canManageFields={(auth?.effective_permissions || []).includes('manage categories') || ['admin', 'owner'].includes(auth?.tenant_role?.toLowerCase() || '')}
                                        assetType="image"
                                        primaryColor={workspaceAccentColor}
                                        sortBy={sort}
                                        sortDirection={sort_direction}
                                        onSortChange={(newSort, newDir) => {
                                            const urlParams = new URLSearchParams(window.location.search)
                                            urlParams.set('sort', newSort)
                                            urlParams.set('sort_direction', newDir)
                                            urlParams.delete('page')
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'next_page_url', 'sort', 'sort_direction', 'compliance_filter'] })
                                        }}
                                        showComplianceFilter={show_compliance_filter}
                                        complianceFilter={compliance_filter}
                                        onComplianceFilterChange={(val) => {
                                            const urlParams = new URLSearchParams(window.location.search)
                                            if (val) urlParams.set('compliance_filter', val)
                                            else urlParams.delete('compliance_filter')
                                            urlParams.delete('page')
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'next_page_url', 'compliance_filter', 'show_compliance_filter'] })
                                        }}
                                        assetResultCount={assetsList?.length ?? 0}
                                        totalInCategory={assetsList?.length ?? 0}
                                        hasMoreAvailable={!!nextPageUrl}
                                    />
                                }
                            />
                        </div>
                        
                        {/* Executions Grid or Empty State */}
                        {assetsList && assetsList.length > 0 ? (
                            <>
                            <AssetGrid 
                                assets={safeAssetsList} 
                                onAssetClick={(asset) => setActiveAssetId(asset?.id || null)}
                                cardSize={cardSize}
                                cardStyle={(auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'}
                                showInfo={showInfo}
                                selectedAssetId={activeAssetId}
                                primaryColor={workspaceAccentColor}
                                selectionAssetType="execution"
                            />
                            {nextPageUrl ? <div ref={loadMoreRef} className="h-10" aria-hidden="true" /> : null}
                            {loading && (
                                <div className="flex justify-center py-6">
                                    <svg className="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                </div>
                            )}
                            {nextPageUrl && <LoadMoreFooter onLoadMore={loadMore} hasMore={!!nextPageUrl} isLoading={loading} />}
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
                            </motion.div>
                        </AnimatePresence>
                    </motion.div>

                    {/* Asset Drawer - Desktop (pushes grid) */}
                    {activeAsset && (
                        <div className="hidden md:block absolute right-0 top-0 bottom-0 z-50">
                            <AssetDrawer
                                asset={activeAsset}
                                onClose={() => {
                                    userClosedDrawerRef.current = true
                                    setActiveAssetId(null)
                                }}
                                assets={safeAssetsList}
                                currentAssetIndex={activeAsset ? safeAssetsList.findIndex(a => a?.id === activeAsset?.id) : -1}
                                onAssetUpdate={handleLifecycleUpdate}
                                bucketAssetIds={bucketAssetIds}
                                onBucketToggle={handleBucketToggle}
                                primaryColor={workspaceAccentColor}
                                selectionAssetType="execution"
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
                        <div className="absolute inset-0 bg-black/50" onClick={() => { userClosedDrawerRef.current = true; setActiveAssetId(null) }} aria-hidden="true" />
                        <AssetDrawer
                            key={activeAssetId} // Key by ID only - prevents remount on asset object changes
                            asset={activeAsset} // May be undefined temporarily during async updates
                            onClose={() => {
                                userClosedDrawerRef.current = true
                                setActiveAssetId(null)
                            }}
                            assets={assetsList}
                            currentAssetIndex={activeAsset ? safeAssetsList.findIndex(a => a?.id === activeAsset?.id) : -1}
                            onAssetUpdate={handleLifecycleUpdate}
                            bucketAssetIds={bucketAssetIds}
                            onBucketToggle={handleBucketToggle}
                            primaryColor={workspaceAccentColor}
                            selectionAssetType="execution"
                        />
                    </div>
                )}
            </div>
            
            {/* Phase 4: Unified Selection ActionBar */}
            <SelectionActionBar
                currentPageIds={safeAssetsList.map((a) => a.id)}
                currentPageItems={safeAssetsList.map((a) => ({
                    id: a.id,
                    type: 'execution',
                    name: a.title ?? a.original_filename ?? '',
                    thumbnail_url: a.final_thumbnail_url ?? a.thumbnail_url ?? a.preview_thumbnail_url ?? null,
                    category_id: a.metadata?.category_id ?? a.category_id ?? null,
                }))}
                onOpenBulkEdit={(ids) => {
                    setBulkSelectedAssetIds(ids)
                    setShowBulkActionsModal(true)
                }}
            />

            {showBulkActionsModal && bulkSelectedAssetIds.length > 0 && (
                <BulkActionsModal
                    assetIds={bulkSelectedAssetIds}
                    selectionSummary={computeSelectionSummary(safeAssetsList, bulkSelectedAssetIds)}
                    onClose={() => setShowBulkActionsModal(false)}
                    onComplete={(result) => {
                        router.reload({ only: ['assets', 'next_page_url'] })
                        if (result?.actionId === 'SOFT_DELETE') {
                            setBulkSelectedAssetIds([])
                            clearSelection()
                        }
                    }}
                    onOpenMetadataEdit={(ids, op) => {
                        setShowBulkActionsModal(false)
                        setBulkSelectedAssetIds(ids)
                        setBulkMetadataInitialOp(op)
                        setShowBulkMetadataModal(true)
                    }}
                />
            )}
            {showBulkMetadataModal && bulkSelectedAssetIds.length > 0 && (
                <BulkMetadataEditModal
                    assetIds={bulkSelectedAssetIds}
                    initialOperation={bulkMetadataInitialOp}
                    onClose={() => {
                        setShowBulkMetadataModal(false)
                        setBulkMetadataInitialOp(null)
                    }}
                    onComplete={() => {
                        router.reload({ only: ['assets', 'next_page_url'] })
                        setBulkSelectedAssetIds([])
                        clearSelection()
                        setShowBulkMetadataModal(false)
                        setBulkMetadataInitialOp(null)
                    }}
                />
            )}

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
