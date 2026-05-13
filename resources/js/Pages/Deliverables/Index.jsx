import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { motion } from 'framer-motion'
import { usePage, router } from '@inertiajs/react'
import { useAssetReconciliation } from '../../hooks/useAssetReconciliation'
import { useThumbnailSmartPoll } from '../../hooks/useThumbnailSmartPoll'
import { usePermission } from '../../hooks/usePermission'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import InfiniteScrollGridSpinner from '../../Components/InfiniteScrollGridSpinner'
import axios from 'axios'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AddAssetButton from '../../Components/AddAssetButton'
import UploadAssetDialog from '../../Components/UploadAssetDialog'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetFilterChipsBar from '../../Components/AssetFilterChipsBar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkActionsModal, { computeSelectionSummary } from '../../Components/BulkActionsModal'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import SelectionActionBar from '../../Components/SelectionActionBar'
import { useSelection } from '../../contexts/SelectionContext'
import { useBucketOptional } from '../../contexts/BucketContext'
import { mergeAsset, warnIfOverwritingCompletedThumbnail, dedupeAssetsById } from '../../utils/assetUtils'
import { computeThumbnailPipelineGridSummary } from '../../utils/assetGridPipelineSummary'
import {
    clearUploadPreviewsOlderThan,
    revokeUploadPreviewIfServerRasterPresent,
} from '../../utils/uploadPreviewRegistry'
import { DELIVERABLES_ITEM_LABEL, DELIVERABLES_ITEM_LABEL_PLURAL } from '../../utils/uiLabels'
import { isUploadAllowedForDroppedFile } from '../../utils/damFileTypes'
import {
    getWorkspaceButtonColor,
    getWorkspaceContextualTone,
    getWorkspaceSidebarForegroundHex,
    getWorkspaceSidebarActiveRowForegroundHex,
    resolveWorkspaceSidebarSurface,
} from '../../utils/colorUtils'
import {
    TagIcon,
    SparklesIcon,
    LockClosedIcon,
    TrashIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import AssetSidebar from '../../Components/AssetSidebar'
import AddCategoryModal from '../../Components/Metadata/AddCategoryModal'
import AddExistingCategoryModal from '../../Components/AddExistingCategoryModal'
import { DeliverablesThumbnailModeProvider, useDeliverablesThumbnailMode } from '../../contexts/DeliverablesThumbnailModeContext'

const DELIVERABLE_GRID_QUERY_KEYS = ['assets', 'next_page_url', 'filtered_grid_total', 'grid_folder_total']

/** Single partial-reload set for category / grid (avoid a categories-only reload + a second navigation). */
const DELIVERABLE_CATEGORY_NAV_ONLY = [
    'filterable_schema',
    'available_values',
    ...DELIVERABLE_GRID_QUERY_KEYS,
    'selected_category',
    'selected_category_slug',
    'compliance_filter',
    'show_compliance_filter',
    'lifecycle',
    'trash_count',
    'can_view_trash',
]

const DELIVERABLE_SIDEBAR_COUNTS_ONLY = ['categories', 'categories_by_type', 'show_all_button', 'total_asset_count']

const DELIVERABLE_BULK_RELOAD_KEYS = [...DELIVERABLE_GRID_QUERY_KEYS, ...DELIVERABLE_SIDEBAR_COUNTS_ONLY]

function DeliverablesIndexPage({ categories, bulk_categories_by_asset_type = null, total_asset_count = 0, selected_category, show_all_button = false, assets = [], next_page_url = null, filtered_grid_total = 0, grid_folder_total = 0, filterable_schema = [], available_values = {}, sort = 'created', sort_direction = 'desc', compliance_filter = '', show_compliance_filter = false, q: searchQuery = '', lifecycle = '', can_view_trash = false, trash_count = 0 }) {
    const pageProps = usePage().props
    const { auth } = pageProps
    const { thumbnailViewMode, setThumbnailViewMode } = useDeliverablesThumbnailMode()
    const { can } = usePermission()
    const canUpload = can('asset.upload')
    const canViewFolderSchema =
        can('metadata.registry.view') || can('metadata.tenant.visibility.manage')

    const [selectedCategoryId, setSelectedCategoryId] = useState(selected_category ? parseInt(selected_category) : null)
    const [tooltipVisible, setTooltipVisible] = useState(null)

    // Keep highlight in sync with the server after partial reloads / Inertia visits (contributors + managers).
    useEffect(() => {
        if (selected_category === undefined) return
        if (selected_category == null || selected_category === '') {
            setSelectedCategoryId((prev) => (prev === null ? prev : null))
            return
        }
        const next = parseInt(String(selected_category), 10)
        if (Number.isNaN(next)) return
        setSelectedCategoryId((prev) => (prev === next ? prev : next))
    }, [selected_category])
    
    // FINAL FIX: Remount key to force page remount after finalize
    const [remountKey, setRemountKey] = useState(0)
    
    // Phase 2 invariant: UploadAssetDialog is controlled via conditional mounting only.
    // Do not convert back to prop-based visibility.
    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false)
    const [gridThumbnailPollPausedForUploadTransfer, setGridThumbnailPollPausedForUploadTransfer] = useState(false)
    
    // Prevent reopening dialog during auto-close timeout (400-700ms delay)
    const [isAutoClosing, setIsAutoClosing] = useState(false)
    
    // Server-driven pagination
    const [assetsList, setAssetsList] = useState(() =>
        dedupeAssetsById(Array.isArray(assets) ? assets.filter(Boolean) : [])
    )
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loading, setLoading] = useState(false)
    const loadMoreRef = useRef(null)

    useEffect(() => {
        const list = dedupeAssetsById(Array.isArray(assets) ? assets.filter(Boolean) : [])
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
            setAssetsList((prev) =>
                dedupeAssetsById([
                    ...(prev || []).filter(Boolean),
                    ...(Array.isArray(data) ? data.filter(Boolean) : []),
                ])
            )
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
    const [openDrawerWithZoom, setOpenDrawerWithZoom] = useState(false)
    const [showBulkActionsModal, setShowBulkActionsModal] = useState(false)
    const [showBulkMetadataModal, setShowBulkMetadataModal] = useState(false)
    const [bulkMetadataInitialOp, setBulkMetadataInitialOp] = useState(null)
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])

    // Category management modals (sidebar plus icon)
    const [addCategoryModalOpen, setAddCategoryModalOpen] = useState(false)
    const [addExistingCategoryOpen, setAddExistingCategoryOpen] = useState(false)
    const userClosedDrawerRef = useRef(false)
    const lastOpenedFromUrlRef = useRef(null)
    
    // Derive active asset from local assets array to prevent stale references
    // CRITICAL: Drawer identity is based ONLY on activeAssetId, not asset object identity
    // Asset object mutations (async updates, thumbnail swaps, etc.) must NOT close the drawer
    const safeAssetsList = (assetsList || []).filter(Boolean)
    const thumbnailPipelineSummary = useMemo(
        () => computeThumbnailPipelineGridSummary(safeAssetsList),
        [safeAssetsList],
    )
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
    const handleBucketToggle = useCallback((assetId, _ev) => {
        if (!bucket) return
        if (bucketAssetIds.includes(assetId)) {
            bucket.bucketRemove(assetId)
        } else {
            bucket.bucketAdd(assetId)
        }
    }, [bucket, bucketAssetIds])

    const handleAssetGridClick = useCallback((asset) => {
        setOpenDrawerWithZoom(false)
        setActiveAssetId(asset?.id || null)
    }, [])
    const handleAssetDoubleClick = useCallback((asset) => {
        setOpenDrawerWithZoom(true)
        setActiveAssetId(asset?.id || null)
    }, [])
    const handleInitialZoomConsumed = useCallback(() => {
        setOpenDrawerWithZoom(false)
    }, [])

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
    }, [safeAssetsList, activeAssetId])

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
        revokeUploadPreviewIfServerRasterPresent(updatedAsset)
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
        isPaused: gridThumbnailPollPausedForUploadTransfer,
    })

    useEffect(() => {
        if (typeof window === 'undefined') return undefined
        const id = window.setInterval(() => {
            clearUploadPreviewsOlderThan(45 * 60 * 1000)
        }, 10 * 60 * 1000)
        return () => window.clearInterval(id)
    }, [])
    
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

    const getStoredGridLayout = () => {
        if (typeof window === 'undefined') return 'grid'
        const v = localStorage.getItem('assetGridLayout')
        return v === 'masonry' ? 'masonry' : 'grid'
    }
    
    // Card size with scaling enabled - loads from localStorage
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    const [layoutMode, setLayoutMode] = useState(getStoredGridLayout)
    
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

    useEffect(() => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('assetGridLayout', layoutMode)
        }
    }, [layoutMode])

    // Handle category selection - triggers Inertia reload with slug-based category query param (?category=slug)
    // ARCHITECTURAL: Reload filterable_schema and available_values so primary/secondary filters match the selected category (same as Assets).
    const handleCategorySelect = (category) => {
        const categoryId = category?.id ?? category // Support both object and ID for backward compatibility
        const categorySlug = category?.slug ?? null
        
        // Do not close the upload dialog here — that unmounts UploadAssetDialog and discards in-progress uploads.
        // initialCategoryId updates; UploadAssetDialog syncs category and metadata schema without clearing files.
        
        setSelectedCategoryId(categoryId)

        let params
        if (categorySlug) {
            params = { category: categorySlug }
        } else {
            const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '')
            urlParams.delete('category')
            urlParams.delete('asset')
            params = Object.fromEntries(urlParams)
        }

        router.get('/app/executions', params, {
            preserveState: true,
            preserveScroll: true,
            only: DELIVERABLE_CATEGORY_NAV_ONLY,
        })
    }

    const handleAddCategorySuccess = useCallback((newCat) => {
        if (newCat?.slug) {
            const rawId = newCat.id
            setSelectedCategoryId(
                rawId != null && rawId !== '' ? parseInt(String(rawId), 10) : null
            )
            router.get('/app/executions', { category: newCat.slug }, {
                preserveState: true,
                preserveScroll: true,
                only: [...DELIVERABLE_CATEGORY_NAV_ONLY, ...DELIVERABLE_SIDEBAR_COUNTS_ONLY],
            })
        } else {
            router.reload({ only: [...DELIVERABLE_SIDEBAR_COUNTS_ONLY] })
        }
    }, [])

    const { isCinematic: sidebarIsCinematic, sidebarColor, backdropCss: sidebarBackdropCss } =
        resolveWorkspaceSidebarSurface(auth.activeBrand)
    const workspaceAccentColor = getWorkspaceButtonColor(auth.activeBrand)
    const textColor = sidebarIsCinematic ? '#ffffff' : getWorkspaceSidebarForegroundHex(sidebarColor)
    // Match Add Execution button: selected/hover use dark hue (same as button hover state)
    const contextualDarkColor = getWorkspaceContextualTone(workspaceAccentColor)
    const activeBgColor = contextualDarkColor
    const activeTextColor = getWorkspaceSidebarActiveRowForegroundHex(contextualDarkColor, textColor)
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
                count: typeof total_asset_count === 'number' && total_asset_count > 0 ? total_asset_count : null,
                category: null,
                categoryId: null,
            })
        }

        visibleMobileCategories.forEach((category) => {
            tabs.push({
                key: String(category.id ?? `template-${category.slug}-${category.asset_type}`),
                label: category.name,
                count: typeof category.asset_count === 'number' && category.asset_count > 0 ? category.asset_count : null,
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

    // Framer Motion swipe: category change via horizontal drag — mobile/tablet only (matches `lg:hidden` tab bar).
    const SWIPE_THRESHOLD = 80
    const SWIPE_VELOCITY_THRESHOLD = 250
    const LG_BREAKPOINT_PX = 1024
    const [viewportWidth, setViewportWidth] = useState(400)
    const [allowCategorySwipeDrag, setAllowCategorySwipeDrag] = useState(false)
    useEffect(() => {
        const update = () => {
            const w = window.innerWidth
            setViewportWidth(w)
            setAllowCategorySwipeDrag(w < LG_BREAKPOINT_PX)
        }
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

    useEffect(() => {
        if (!allowCategorySwipeDrag) {
            setDragOffsetX(0)
        }
    }, [allowCategorySwipeDrag])

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
    
    /** Soft refresh while upload dialog stays open (minimized tray after Finalize). */
    const handleFinalizeAccepted = useCallback(() => {
        router.reload({
            only: [...DELIVERABLE_GRID_QUERY_KEYS, ...DELIVERABLE_SIDEBAR_COUNTS_ONLY, 'trash_count'],
            preserveScroll: true,
            preserveState: true,
        })
    }, [])

    // Handle finalize complete - refresh asset grid after successful upload finalize
    // Match Assets/Index behavior: preserve drawer state by only reloading assets prop
    const handleFinalizeComplete = useCallback(() => {
        // Set auto-closing flag to prevent reopening during timeout
        setIsAutoClosing(true)
        
        // Ensure dialog stays closed during reload
        setIsUploadDialogOpen(false)
        
        // Force page remount by incrementing remount key
        setRemountKey(prev => prev + 1)
        
        // Reload grid and sidebar category counts (same contract as Assets/Index after finalize).
        router.reload({
            only: [
                ...DELIVERABLE_GRID_QUERY_KEYS,
                'categories',
                'show_all_button',
                'total_asset_count',
                'trash_count',
            ],
            preserveScroll: true,
            preserveState: false, // Prevent state preservation to avoid dialog reopening
            onSuccess: () => {
                setIsUploadDialogOpen(false)
                // Reset auto-closing flag after reload completes
                setIsAutoClosing(false)
            },
        })
    }, [])
    
    // Drag-and-drop state for files dropped on grid
    const [droppedFiles, setDroppedFiles] = useState(null)
    const [isDraggingOver, setIsDraggingOver] = useState(false)
    
    // BUGFIX: Single handler to open upload dialog
    const handleOpenUploadDialog = useCallback((files = null) => {
        if (isAutoClosing) {
            return
        }
        if (files) {
            setDroppedFiles(files)
        }
        try { sessionStorage.removeItem('uploadAssetDialogMinimized') } catch (_) { /* ignore */ }
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
            const imageFiles = files.filter((f) => isUploadAllowedForDroppedFile(f, pageProps.dam_file_types))
            if (imageFiles.length > 0) {
                handleOpenUploadDialog(imageFiles)
            }
        }
    }, [canUpload, handleOpenUploadDialog, pageProps.dam_file_types])

    return (
        <div key={pageKey} className="h-screen flex flex-col overflow-hidden" data-category-id={selectedCategoryId ?? 'all'}>
            <AppHead title="Deliverables" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <AssetSidebar
                        addAssetButton={
                            canUpload && auth?.user ? (
                                <AddAssetButton
                                    defaultAssetType="deliverable"
                                    className="w-full"
                                    onClick={handleOpenUploadDialog}
                                />
                            ) : null
                        }
                        categories={categories}
                        filterCategories={(cats) =>
                            (cats || []).filter(
                                (c) =>
                                    !(
                                        c.is_hidden === true ||
                                        c.is_hidden === 1 ||
                                        c.is_hidden === '1' ||
                                        c.is_hidden === 'true'
                                    )
                            )
                        }
                        showAllButton={show_all_button}
                        totalAssetCount={total_asset_count}
                        selectedCategoryId={selectedCategoryId}
                        onCategorySelect={handleCategorySelect}
                        lifecycle={lifecycle}
                        source=""
                        canViewTrash={can_view_trash}
                        trashCount={trash_count}
                        researchCount={0}
                        stagedCount={0}
                        showStaged={false}
                        showResearch={false}
                        baseUrl="/app/executions"
                        onTrashClick={() => router.get('/app/executions', { lifecycle: 'deleted' })}
                        sidebarColor={sidebarColor}
                        sidebarBackdropCss={sidebarBackdropCss}
                        workspaceAccentColor={workspaceAccentColor}
                        tooltipVisible={tooltipVisible}
                        setTooltipVisible={setTooltipVisible}
                        emptyMessage="No execution categories yet"
                        canManageCategoriesAndFields={(can('metadata.registry.view') || can('metadata.tenant.visibility.manage')) || can('brand_categories.manage')}
                        activeBrandId={auth?.activeBrand?.id ?? null}
                        onAddCategoryClick={can('brand_categories.manage') ? () => setAddCategoryModalOpen(true) : undefined}
                        showFolderSchemaHelp={canViewFolderSchema}
                    />
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
                                                {tab.count != null ? (
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
                        <AssetFilterChipsBar
                            filterable_schema={filterable_schema}
                            available_values={available_values}
                            inertiaOnly={[
                                ...DELIVERABLE_GRID_QUERY_KEYS,
                                'filters',
                                'uploaded_by_users',
                                'q',
                                'compliance_filter',
                                'show_compliance_filter',
                            ]}
                        />
                    </div>
                    <motion.div
                        className={`flex-1 min-h-0 overflow-y-auto overflow-x-hidden relative pb-0 touch-pan-y ${activeAssetId ? 'md:pr-[480px]' : ''}`}
                        style={{ touchAction: 'pan-y' }}
                        drag={mobileCategoryTabs.length > 1 && allowCategorySwipeDrag ? 'x' : false}
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
                        {/* No AnimatePresence/category key fade — that matched Assets and avoids double-flash on category change. */}
                        <div
                            className="py-6 px-4 sm:px-6 lg:px-8"
                            style={{
                                opacity: Math.max(0.55, 1 - Math.min(Math.abs(dragOffsetX) / 100, 0.45)),
                            }}
                        >
                        {/* Asset Grid Toolbar - Always visible (persists across categories) */}
                        {/* Matches Assets/Index behavior - toolbar always visible, even when no assets */}
                        <div className="mb-8">
                            <AssetGridToolbar
                                showInfo={showInfo}
                                onToggleInfo={() => setShowInfo(v => !v)}
                                cardSize={cardSize}
                                onCardSizeChange={setCardSize}
                                layoutMode={layoutMode}
                                onLayoutModeChange={setLayoutMode}
                                thumbnailPipelineSummary={thumbnailPipelineSummary}
                                primaryColor={workspaceAccentColor}
                                primaryMetadataFiltersAssetType="deliverable"
                                filterable_schema={filterable_schema}
                                selectedCategoryId={selectedCategoryId}
                                available_values={available_values}
                                searchTagAutocompleteTenantId={auth?.activeCompany?.id}
                                searchPlaceholder="Search items, titles, tags…"
                                sortBy={sort}
                                sortDirection={sort_direction}
                                onSortChange={(newSort, newDir) => {
                                    const urlParams = new URLSearchParams(window.location.search)
                                    urlParams.set('sort', newSort)
                                    urlParams.set('sort_direction', newDir)
                                    urlParams.delete('page')
                                    router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: [...DELIVERABLE_GRID_QUERY_KEYS, 'sort', 'sort_direction', 'compliance_filter'] })
                                }}
                                showComplianceFilter={show_compliance_filter}
                                clearFiltersInertiaOnly={[...DELIVERABLE_GRID_QUERY_KEYS, 'filters', 'uploaded_by_users', 'q', 'compliance_filter', 'show_compliance_filter']}
                                showMoreFilters={true}
                                executionThumbnailViewMode={thumbnailViewMode}
                                onExecutionThumbnailViewModeChange={setThumbnailViewMode}
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
                                        assetType="deliverable"
                                        primaryColor={workspaceAccentColor}
                                        sortBy={sort}
                                        sortDirection={sort_direction}
                                        onSortChange={(newSort, newDir) => {
                                            const urlParams = new URLSearchParams(window.location.search)
                                            urlParams.set('sort', newSort)
                                            urlParams.set('sort_direction', newDir)
                                            urlParams.delete('page')
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: [...DELIVERABLE_GRID_QUERY_KEYS, 'sort', 'sort_direction', 'compliance_filter'] })
                                        }}
                                        showComplianceFilter={show_compliance_filter}
                                        complianceFilter={compliance_filter}
                                        onComplianceFilterChange={(val) => {
                                            const urlParams = new URLSearchParams(window.location.search)
                                            if (val) urlParams.set('compliance_filter', val)
                                            else urlParams.delete('compliance_filter')
                                            urlParams.delete('page')
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: [...DELIVERABLE_GRID_QUERY_KEYS, 'compliance_filter', 'show_compliance_filter'] })
                                        }}
                                        assetResultCount={assetsList?.length ?? 0}
                                        totalInCategory={assetsList?.length ?? 0}
                                        filteredGridTotal={typeof filtered_grid_total === 'number' ? filtered_grid_total : null}
                                        gridFolderTotal={typeof grid_folder_total === 'number' ? grid_folder_total : null}
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
                                onAssetClick={handleAssetGridClick}
                                onAssetDoubleClick={handleAssetDoubleClick}
                                cardSize={cardSize}
                                layoutMode={layoutMode}
                                cardStyle={(auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'}
                                showInfo={showInfo}
                                selectedAssetId={activeAssetId}
                                primaryColor={workspaceAccentColor}
                                selectionAssetType="execution"
                                executionThumbnailViewMode={thumbnailViewMode}
                            />
                            {nextPageUrl ? <div ref={loadMoreRef} className="h-10" aria-hidden="true" /> : null}
                            {loading && <InfiniteScrollGridSpinner brand={auth?.activeBrand} />}
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
                                            ? `No ${DELIVERABLES_ITEM_LABEL_PLURAL} in this folder yet`
                                            : `No ${DELIVERABLES_ITEM_LABEL_PLURAL} yet`}
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    {searchQuery?.trim()
                                        ? 'Try different keywords or clear the search to see all executions.'
                                        : selectedCategoryId
                                            ? `Get started by uploading your first ${DELIVERABLES_ITEM_LABEL} to this folder. Manage your brand assets with ease and keep everything organized.`
                                            : `Get started by choosing a folder or uploading your first ${DELIVERABLES_ITEM_LABEL}. Manage your brand assets with ease and keep everything in sync.`}
                                </p>
                                {canUpload ? (
                                    <div className="mt-8">
                                        <AddAssetButton
                                            defaultAssetType="deliverable"
                                            onClick={handleOpenUploadDialog}
                                        />
                                    </div>
                                ) : null}
                            </div>
                        )}
                        </div>
                    </motion.div>

                    {/* Asset Drawer — single instance, portals to document.body */}
                    {activeAssetId && (
                        <AssetDrawer
                            key={activeAssetId}
                            asset={activeAsset}
                            onClose={() => {
                                userClosedDrawerRef.current = true
                                setActiveAssetId(null)
                                setOpenDrawerWithZoom(false)
                            }}
                            assets={safeAssetsList}
                            currentAssetIndex={activeAsset ? safeAssetsList.findIndex(a => a?.id === activeAsset?.id) : -1}
                            onAssetUpdate={handleLifecycleUpdate}
                            bucketAssetIds={bucketAssetIds}
                            onBucketToggle={handleBucketToggle}
                            primaryColor={workspaceAccentColor}
                            selectionAssetType="execution"
                            initialZoomOpen={openDrawerWithZoom}
                            onInitialZoomConsumed={handleInitialZoomConsumed}
                        />
                    )}

                    {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on category change */}
                </div>
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
                    selectedAssetSummary={bulkSelectedAssetIds.map((id) => {
                        const a = safeAssetsList.find((x) => x.id === id)
                        return a
                            ? {
                                  id: a.id,
                                  original_filename: a.original_filename ?? '',
                                  mime_type: a.mime_type ?? null,
                                  title: a.title ?? null,
                              }
                            : { id, original_filename: '', mime_type: null, title: null }
                    })}
                    selectionSummary={computeSelectionSummary(safeAssetsList, bulkSelectedAssetIds)}
                    categories={categories ?? []}
                    bulkCategoriesByAssetType={bulk_categories_by_asset_type}
                    defaultAssignAssetType="deliverable"
                    onClose={() => setShowBulkActionsModal(false)}
                    onComplete={(result) => {
                        router.reload({ only: [...DELIVERABLE_BULK_RELOAD_KEYS] })
                        if (result?.actionId === 'SOFT_DELETE' || result?.assignCategory) {
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
                        router.reload({ only: [...DELIVERABLE_BULK_RELOAD_KEYS] })
                        setBulkSelectedAssetIds([])
                        clearSelection()
                        setShowBulkMetadataModal(false)
                        setBulkMetadataInitialOp(null)
                    }}
                />
            )}

            {/* Category management modals */}
            {addCategoryModalOpen && auth?.activeBrand && (
                <AddCategoryModal
                    isOpen={true}
                    onClose={() => setAddCategoryModalOpen(false)}
                    assetType="deliverable"
                    brandId={auth.activeBrand.id}
                    brandName={auth.activeBrand.name ?? ''}
                    categoryLimits={null}
                    canViewMetadataRegistry={can('metadata.registry.view') || can('metadata.tenant.visibility.manage')}
                    onSuccess={handleAddCategorySuccess}
                />
            )}
            {addExistingCategoryOpen && auth?.activeBrand && (
                <AddExistingCategoryModal
                    isOpen={true}
                    onClose={() => setAddExistingCategoryOpen(false)}
                    brandId={auth.activeBrand.id}
                    assetType="deliverable"
                    onSuccess={() => {
                        router.reload({ only: ['categories', 'categories_by_type', 'show_all_button', 'total_asset_count'] })
                        setAddExistingCategoryOpen(false)
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
                    onFinalizeAccepted={handleFinalizeAccepted}
                    onGridTransferActiveChange={setGridThumbnailPollPausedForUploadTransfer}
                    initialFiles={droppedFiles}
                />
            )}
        </div>
    )
}

export default function DeliverablesIndex(props) {
    return (
        <DeliverablesThumbnailModeProvider>
            <DeliverablesIndexPage {...props} />
        </DeliverablesThumbnailModeProvider>
    )
}
