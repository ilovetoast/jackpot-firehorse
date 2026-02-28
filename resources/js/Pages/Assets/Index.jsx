import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AddAssetButton from '../../Components/AddAssetButton'
import UploadAssetDialog from '../../Components/UploadAssetDialog'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridMetadataPrimaryFilters from '../../Components/AssetGridMetadataPrimaryFilters'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkActionsModal, { computeSelectionSummary } from '../../Components/BulkActionsModal'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import SelectionActionBar from '../../Components/SelectionActionBar'
import { useSelection } from '../../contexts/SelectionContext'
import { mergeAsset, warnIfOverwritingCompletedThumbnail } from '../../utils/assetUtils'
import { useAssetReconciliation } from '../../hooks/useAssetReconciliation'
import { useThumbnailSmartPoll } from '../../hooks/useThumbnailSmartPoll'
import { filterActiveCategories } from '../../utils/categoryUtils'
import { getWorkspaceButtonColor, getContrastTextColor, darkenColor } from '../../utils/colorUtils'
import { shouldPurgeOnCategoryChange } from '../../utils/filterQueryOwnership'
import { isCategoryCompatible } from '../../utils/filterScopeRules'
import { parseFiltersFromUrl } from '../../utils/filterUrlUtils'
import { usePermission } from '../../hooks/usePermission'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import OnlineUsersIndicator from '../../Components/OnlineUsersIndicator'
import axios from 'axios'
import {
    FolderIcon,
    TagIcon,
    LockClosedIcon,
    TrashIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

export default function AssetsIndex({ categories, categories_by_type, selected_category, show_all_button = false, total_asset_count = 0, assets = [], next_page_url = null, filterable_schema = [], saved_views = [], available_values = {}, sort = 'created', sort_direction = 'desc', q: searchQuery = '', lifecycle = '', can_view_trash = false, trash_count = 0 }) {
    const pageProps = usePage().props
    const { auth } = pageProps
    const { can } = usePermission()
    const canUpload = can('asset.upload')
    
    // Use prop directly (now in function signature) or fallback to pageProps
    const availableValues = available_values || pageProps.available_values || {}
    const category_id = selected_category ? parseInt(selected_category) : null
    const asset_type = 'image' // Default for asset grid (most assets are images)
    
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
    
    // Server-driven pagination: assets list and next page URL (single source of truth)
    const [assetsList, setAssetsList] = useState(Array.isArray(assets) ? assets.filter(Boolean) : [])
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loading, setLoading] = useState(false)
    const loadMoreRef = useRef(null)
    const loadMoreAbortRef = useRef(null)

    // Sync from Inertia props when page context changes (initial load, category, filters, search, sort).
    // Safe: load_more is pure axios and never triggers Inertia reload, so props.assets/next_page_url
    // do not change on append — this effect only runs on real navigation/filter changes.
    useEffect(() => {
        if (loadMoreAbortRef.current) {
            loadMoreAbortRef.current.abort()
            loadMoreAbortRef.current = null
        }
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
            console.info('[ASSET_GRID_TIMING] navigation to first grid render', { ms, assetCount: list.length })
        }
    }, [assets, next_page_url])

    // Load next page (append to list)
    const loadMore = useCallback(async () => {
        if (!nextPageUrl || loading) return
        setLoading(true)
        const ac = new AbortController()
        loadMoreAbortRef.current = ac
        try {
            // Preserve all query params from next_page_url; append load_more=1 with correct separator (no ? if URL has none)
            const separator = nextPageUrl.includes('?') ? '&' : '?'
            const url = nextPageUrl + separator + 'load_more=1'
            const response = await axios.get(url, { signal: ac.signal })
            const data = response.data?.data ?? []
            setAssetsList(prev => [...prev, ...(Array.isArray(data) ? data : [])])
            setNextPageUrl(response.data?.next_page_url ?? null)
        } catch (e) {
            if (e.name === 'CanceledError' || e.code === 'ERR_CANCELED') return
            console.error('Infinite scroll failed', e)
            if (e.response?.status === 500) setNextPageUrl(null)
        } finally {
            if (loadMoreAbortRef.current === ac) loadMoreAbortRef.current = null
            setLoading(false)
        }
    }, [nextPageUrl, loading])

    // IntersectionObserver: load more when sentinel enters viewport
    useEffect(() => {
        if (!loadMoreRef.current) return
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting && nextPageUrl && !loading) {
                    loadMore()
                }
            },
            { rootMargin: '200px' }
        )
        observer.observe(loadMoreRef.current)
        return () => observer.disconnect()
    }, [nextPageUrl, loading, loadMore])
    
    // Store only asset ID to prevent stale object references after Inertia reloads
    // The active asset is derived from the current assets array, ensuring it always reflects fresh data
    const [activeAssetId, setActiveAssetId] = useState(null) // Asset ID selected for drawer
    // Prevent URL/search effect from re-opening drawer after user explicitly closes it
    const userClosedDrawerRef = useRef(false)
    const lastOpenedFromUrlRef = useRef(null)
    
    // Phase 2 – Step 7: Bulk selection state
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])
    const [showBulkActionsModal, setShowBulkActionsModal] = useState(false)
    const [showBulkMetadataModal, setShowBulkMetadataModal] = useState(false)

    // UX: Click on asset card always opens drawer. Checkbox uses SelectionContext.
    const handleAssetClick = useCallback((asset) => {
        setActiveAssetId(asset?.id || null)
    }, [])

    const { selectedCount, clearSelection, getSelectedOnPage } = useSelection()

    // Derive active asset from local assets array to prevent stale references
    // CRITICAL: Drawer identity is based ONLY on activeAssetId, not asset object identity
    // Asset object mutations (async updates, thumbnail swaps, etc.) must NOT close the drawer
    const safeAssetsList = (assetsList || []).filter(Boolean)
    const activeAsset = activeAssetId ? safeAssetsList.find(asset => asset?.id === activeAssetId) : null
    
    // Close drawer ONLY if active asset ID truly doesn't exist in current assets array
    // This check is robust against temporary nulls during async updates
    // We check for ID existence, not object reference equality
    useEffect(() => {
        if (activeAssetId) {
            const assetExists = safeAssetsList.some(asset => asset?.id === activeAssetId)
            if (!assetExists) {
                // Asset ID no longer exists in array - close drawer
                setActiveAssetId(null)
            }
        }
    }, [activeAssetId, safeAssetsList])

    // Category switches should reset the drawer selection,
    // but must NOT remount the entire page (that destroys <img> nodes and causes flashes).
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
    // Also when ?q=uuid (asset ID search): open drawer if the asset is in results
    // Also clear staleness flag on mount (navigation to /app/assets completes)
    // CRITICAL: Do NOT re-open when user has explicitly closed the drawer (X button)
    useEffect(() => {
        // Clear staleness flag when navigating to assets page (view is synced)
        if (typeof window !== 'undefined' && window.__assetGridStaleness) {
            window.__assetGridStaleness.hasStaleAssetGrid = false
            window.dispatchEvent(new CustomEvent('assetGridStalenessChanged', {
                detail: { hasStaleAssetGrid: false }
            }))
        }
        
        if (typeof window !== 'undefined' && safeAssetsList.length > 0) {
            const urlParams = new URLSearchParams(window.location.search)
            let assetId = urlParams.get('asset')
            // If no ?asset= but q looks like a UUID, treat q as asset ID (e.g. direct link ?q=uuid)
            if (!assetId && searchQuery) {
                const uuidRegex = /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i
                const m = searchQuery.trim().match(/^(?:id|asset):\s*(.+)$/i)
                const candidate = m ? m[1].trim() : searchQuery.trim()
                if (uuidRegex.test(candidate)) assetId = candidate
            }
            if (assetId) {
                const asset = safeAssetsList.find(a => a?.id === assetId)
                if (asset) {
                    // Respect user's explicit close: don't re-open same asset from URL/search
                    if (userClosedDrawerRef.current && assetId === lastOpenedFromUrlRef.current) {
                        return
                    }
                    setActiveAssetId(assetId)
                    lastOpenedFromUrlRef.current = assetId
                    userClosedDrawerRef.current = false
                }
            }
        }
    }, [safeAssetsList, searchQuery])
    
    // Category-switch filter cleanup (query pruning)
    // Uses filterQueryOwnership and filterScopeRules to determine which filters to purge
    // This ensures incompatible filters are removed when switching categories
    //
    // IMPORTANT: Skip when change came from handleCategorySelect - that already does router.get.
    // This effect only runs for external URL changes (e.g. browser back/forward).
    const prevCategoryIdRef = useRef(selectedCategoryId)
    const categoryChangeFromClickRef = useRef(false)
    useEffect(() => {
        const prevCategoryId = prevCategoryIdRef.current
        const nextCategoryId = selectedCategoryId

        // Skip if this change came from handleCategorySelect - avoid double router.get (causes white flash)
        if (categoryChangeFromClickRef.current) {
            categoryChangeFromClickRef.current = false
            prevCategoryIdRef.current = nextCategoryId
            return
        }

        // Only run cleanup if category actually changed
        if (prevCategoryId === nextCategoryId) {
            prevCategoryIdRef.current = nextCategoryId
            return
        }
        
        // Update ref for next comparison
        prevCategoryIdRef.current = nextCategoryId
        
        // Parse current URL query params
        const urlParams = new URLSearchParams(window.location.search)
        let hasChanges = false
        
        // Step 1: Clean up individual query params using filterQueryOwnership
        // Remove params that should be purged on category change
        // This handles params like 'orientation', 'dimensions', etc.
        const paramsToRemove = []
        for (const [param, value] of urlParams.entries()) {
            // Skip 'category' param (it's the category selector itself)
            if (param === 'category') {
                continue
            }
            
            // Use filterQueryOwnership to determine if param should be purged
            if (shouldPurgeOnCategoryChange(param)) {
                paramsToRemove.push(param)
                hasChanges = true
            }
        }
        
        // Remove params that should be purged
        paramsToRemove.forEach(param => {
            urlParams.delete(param)
        })
        
        // Step 2: Clean up metadata filters (URL may have 'filters' JSON or flat params) using filterScopeRules
        const filterKeys = (filterable_schema || []).map(f => f.field_key || f.key).filter(Boolean)
        const filters = parseFiltersFromUrl(urlParams, filterKeys)
        if (Object.keys(filters).length > 0) {
            if (nextCategoryId === null) {
                filterKeys.forEach(k => urlParams.delete(k))
                urlParams.delete('filters')
                hasChanges = true
            } else if (prevCategoryId !== null && prevCategoryId !== nextCategoryId && filterable_schema.length > 0) {
                const compatibleFilters = {}
                Object.entries(filters).forEach(([fieldKey, filterDef]) => {
                    const filterDescriptor = filterable_schema.find(field => (field.field_key || field.key) === fieldKey)
                    if (filterDescriptor && isCategoryCompatible(filterDescriptor, nextCategoryId)) {
                        compatibleFilters[fieldKey] = filterDef
                    } else {
                        hasChanges = true
                    }
                })
                filterKeys.forEach(k => urlParams.delete(k))
                urlParams.delete('filters')
                Object.entries(compatibleFilters).forEach(([key, def]) => {
                    const v = def?.value
                    if (v != null && v !== '' && (!Array.isArray(v) || v.length > 0)) {
                        urlParams.set(key, Array.isArray(v) ? String(v[0]) : String(v))
                    }
                })
            } else if (prevCategoryId !== null && prevCategoryId !== nextCategoryId) {
                filterKeys.forEach(k => urlParams.delete(k))
                urlParams.delete('filters')
                hasChanges = true
            }
        }
        
        // Apply cleanup to URL if we made changes
        // Only update once (no loops) - this is a single cleanup pass
        if (hasChanges) {
            router.get(window.location.pathname, Object.fromEntries(urlParams), {
                preserveState: true,
                preserveScroll: true,
                only: ['assets', 'next_page_url'],
            })
        }
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
    // This preserves drawer state and grid scroll position
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

    // Single consolidated filter debug log (opt-in: set window.__DEBUG_FILTERS__ = true in console)
    useEffect(() => {
        if (typeof window === 'undefined' || !window.__DEBUG_FILTERS__) return
        const urlParams = new URLSearchParams(window.location.search)
        const filterKeys = (filterable_schema || []).map((f) => f.field_key || f.key).filter(Boolean)
        const filters = parseFiltersFromUrl(urlParams, filterKeys)
        const appliedFilterValues = Object.fromEntries(
            Object.entries(filters).map(([k, def]) => [k, def?.value])
        )
        const sample = (assetsList || []).slice(0, 3).map((a) => {
            const fields = a.metadata?.fields || {}
            const sampleFields = {}
            Object.keys(appliedFilterValues).forEach((key) => {
                sampleFields[key] = fields[key] ?? a.metadata?.[key] ?? null
            })
            return { id: a.id, title: a.title, filter_fields: sampleFields }
        })
        console.log('[filters] troubleshooting (set window.__DEBUG_FILTERS__ = false to disable)', {
            appliedFilters: appliedFilterValues,
            category_id: selectedCategoryId,
            assetCount: (assetsList || []).length,
            sampleAssets: sample,
        })
    }, [assetsList, selectedCategoryId, filterable_schema])

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

    // Card style from brand settings: clean (guidelines) | impact (default)
    const cardStyle = (auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'

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
    const handleCategorySelect = useCallback((category) => {
        const categoryId = category?.id ?? category // Support both object and ID for backward compatibility
        const categorySlug = category?.slug ?? null

        // Signal to useEffect (filter cleanup) to skip - prevents double router.get and white flash
        categoryChangeFromClickRef.current = true

        // Phase 2 invariant: Explicitly reset dialog state before preserveState navigation
        setIsUploadDialogOpen(false)

        setSelectedCategoryId(categoryId)

        router.get('/app/assets',
            categorySlug ? { category: categorySlug } : {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['filterable_schema', 'available_values', 'assets', 'next_page_url', 'selected_category', 'selected_category_slug']
            }
        )
    }, [])

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

    // Phase L.6.2: Detect pending publication or unpublished mode from URL
    const [isPendingApprovalMode, setIsPendingApprovalMode] = useState(() => {
        if (typeof window !== 'undefined') {
            const urlParams = new URLSearchParams(window.location.search)
            const lifecycle = urlParams.get('lifecycle')
            return lifecycle === 'pending_approval' || lifecycle === 'unpublished'
        }
        return false
    })
    
    // Phase J.3.1: Detect pending_publication filter for contributor micro-indicators
    const [isPendingPublicationFilter, setIsPendingPublicationFilter] = useState(() => {
        if (typeof window !== 'undefined') {
            const urlParams = new URLSearchParams(window.location.search)
            const lifecycle = urlParams.get('lifecycle')
            return lifecycle === 'pending_publication'
        }
        return false
    })
    
    // Update when URL changes (e.g., when filter is toggled)
    useEffect(() => {
        const checkUrl = () => {
            if (typeof window !== 'undefined') {
                const urlParams = new URLSearchParams(window.location.search)
                const lifecycle = urlParams.get('lifecycle')
                setIsPendingApprovalMode(lifecycle === 'pending_approval' || lifecycle === 'unpublished')
                setIsPendingPublicationFilter(lifecycle === 'pending_publication')
            }
        }
        
        // Check on mount and when page props change (Inertia reloads)
        checkUrl()
        
        // Also check periodically in case URL changes without Inertia navigation
        const interval = setInterval(checkUrl, 500)
        
        return () => clearInterval(interval)
    }, [])
    
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
    // Match Add Asset button: selected/hover use dark hue (same as button hover state)
    const contextualDarkColor = darkenColor(workspaceAccentColor, 20)
    const activeBgColor = contextualDarkColor
    const activeTextColor = getContrastTextColor(contextualDarkColor)
    const hoverBgColor = contextualDarkColor
    // Unselected: reduced opacity for visual hierarchy (matches icon treatment)
    const unselectedTextColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.65)' : 'rgba(0, 0, 0, 0.65)'
    const unselectedIconColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)'
    const unselectedCountColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)'
    

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppHead title="Assets" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-64 xl:w-72 h-full transition-[width] duration-200" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-4 pb-3 lg:pt-5 lg:pb-4 overflow-y-auto">
                            <nav className="mt-3 lg:mt-5 flex-1 px-1.5 lg:px-2 space-y-1">
                                {/* Add Asset Button - Persistent in sidebar (only show if user has upload permissions) */}
                                {auth?.user && (
                                    <div className="px-2 py-1.5 lg:px-3 lg:py-2 mb-3 lg:mb-4">
                                        <AddAssetButton 
                                            defaultAssetType="asset" 
                                            className="w-full"
                                            onClick={handleOpenUploadDialog}
                                            disabled={isAutoClosing}
                                        />
                                    </div>
                                )}
                                
                                {/* IMPORTANT: Sidebar category navigation is independent of filter state */}
                                {/* Categories - Always visible when categories exist */}
                                {categories && categories.length > 0 && (
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
                                                        backgroundColor: (selectedCategoryId === null || selectedCategoryId === undefined) && lifecycle !== 'deleted' ? activeBgColor : 'transparent',
                                                        color: (selectedCategoryId === null || selectedCategoryId === undefined) && lifecycle !== 'deleted' ? activeTextColor : unselectedTextColor,
                                                    }}
                                                    onMouseEnter={(e) => {
                                                        if ((selectedCategoryId !== null && selectedCategoryId !== undefined) || lifecycle === 'deleted') {
                                                            e.currentTarget.style.backgroundColor = hoverBgColor
                                                            e.currentTarget.style.color = activeTextColor
                                                        }
                                                    }}
                                                    onMouseLeave={(e) => {
                                                        if (selectedCategoryId !== null && selectedCategoryId !== undefined) {
                                                            e.currentTarget.style.backgroundColor = 'transparent'
                                                            e.currentTarget.style.color = unselectedTextColor
                                                        }
                                                    }}
                                                >
                                                    <TagIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: (selectedCategoryId === null || selectedCategoryId === undefined) ? activeTextColor : unselectedIconColor }} />
                                                    <span className="flex-1">All</span>
                                                    {total_asset_count > 0 && (
                                                        <span className="text-xs font-normal opacity-80" style={{ color: (selectedCategoryId === null || selectedCategoryId === undefined) ? activeTextColor : unselectedCountColor }}>
                                                            {total_asset_count}
                                                        </span>
                                                    )}
                                                </button>
                                            )}
                                            {/* Show all categories - filterActiveCategories already filters out hidden categories */}
                                            {filterActiveCategories(categories)
                                                .map((category) => {
                                                    const isSelected = selectedCategoryId === category.id && selectedCategoryId !== null && selectedCategoryId !== undefined
                                                    return (
                                                    <button
                                                        key={category.id}
                                                        onClick={() => handleCategorySelect(category)}
                                                        className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                        style={{
                                                            backgroundColor: isSelected ? activeBgColor : 'transparent',
                                                            color: isSelected ? activeTextColor : unselectedTextColor,
                                                        }}
                                                        onMouseEnter={(e) => {
                                                            if (!isSelected) {
                                                                e.currentTarget.style.backgroundColor = hoverBgColor
                                                                e.currentTarget.style.color = activeTextColor
                                                            }
                                                        }}
                                                        onMouseLeave={(e) => {
                                                            if (!isSelected) {
                                                                e.currentTarget.style.backgroundColor = 'transparent'
                                                                e.currentTarget.style.color = unselectedTextColor
                                                            }
                                                        }}
                                                    >
                                                        <CategoryIcon 
                                                            iconId={category.icon || 'folder'} 
                                                            className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" 
                                                            style={{ color: isSelected ? activeTextColor : unselectedIconColor }}
                                                        />
                                                        <span className="flex-1">{category.name}</span>
                                                        {category.asset_count !== undefined && category.asset_count > 0 && (
                                                            <span className="text-xs font-normal opacity-80 ml-2" style={{ color: isSelected ? activeTextColor : unselectedCountColor }}>
                                                                {category.asset_count}
                                                            </span>
                                                        )}
                                                        {category.is_private && (
                                                            <div className="relative ml-2 group">
                                                                <LockClosedIcon 
                                                                    className="h-4 w-4 flex-shrink-0 cursor-help" 
                                                                style={{ color: isSelected ? activeTextColor : unselectedIconColor }}
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
                                            }
                                            {/* Phase B2: Trash at bottom - only when has items or already on trash view */}
                                            {can_view_trash && (trash_count > 0 || lifecycle === 'deleted') && (
                                                <>
                                                    <div className="my-1.5 border-t" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.12)' }} />
                                                    <button
                                                        onClick={() => router.get('/app/assets', { lifecycle: 'deleted' })}
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
                                        </div>
                                    </div>
                                )}
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

                {/* Mobile: Categories slide-out overlay + panel (visible when lg:hidden) */}
                {mobileCategoriesOpen && (
                    <>
                        <div
                            className="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden"
                            aria-hidden="true"
                            onClick={() => setMobileCategoriesOpen(false)}
                        />
                        <div
                            className="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] flex flex-col shadow-xl lg:hidden"
                            style={{ backgroundColor: sidebarColor, top: '5rem' }}
                            role="dialog"
                            aria-modal="true"
                            aria-label="Categories"
                        >
                            <div className="flex items-center justify-between h-14 px-4 border-b shrink-0" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.1)' }}>
                                <span className="text-sm font-semibold" style={{ color: textColor }}>Categories</span>
                                <button
                                    type="button"
                                    onClick={() => setMobileCategoriesOpen(false)}
                                    className="rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white/50"
                                    style={{ color: textColor }}
                                    aria-label="Close categories"
                                >
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <div className="flex-1 overflow-y-auto py-4 px-2">
                                {auth?.user && (
                                    <div className="px-2 py-2 mb-3">
                                        <AddAssetButton defaultAssetType="asset" className="w-full" onClick={() => { handleOpenUploadDialog(); setMobileCategoriesOpen(false) }} disabled={isAutoClosing} />
                                    </div>
                                )}
                                {categories && categories.length > 0 && (
                                    <div className="space-y-0.5">
                                        {show_all_button && (
                                            <button
                                                onClick={() => { handleCategorySelect(null); setMobileCategoriesOpen(false) }}
                                                className="flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left"
                                                style={{ backgroundColor: selectedCategoryId == null ? activeBgColor : 'transparent', color: selectedCategoryId == null ? activeTextColor : textColor }}
                                            >
                                                <TagIcon className="mr-3 h-5 w-5 opacity-80" style={{ color: selectedCategoryId == null ? activeTextColor : textColor }} />
                                                <span className="flex-1">All</span>
                                                {total_asset_count > 0 && <span className="text-xs opacity-80">{total_asset_count}</span>}
                                            </button>
                                        )}
                                        {filterActiveCategories(categories).map((category) => {
                                            const isSelected = selectedCategoryId === category.id
                                            return (
                                                <button
                                                    key={category.id}
                                                    onClick={() => { handleCategorySelect(category); setMobileCategoriesOpen(false) }}
                                                    className="flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left"
                                                    style={{ backgroundColor: isSelected ? activeBgColor : 'transparent', color: isSelected ? activeTextColor : textColor }}
                                                >
                                                    <CategoryIcon iconId={category.icon || 'folder'} className="mr-3 h-5 w-5 opacity-80" style={{ color: isSelected ? activeTextColor : textColor }} />
                                                    <span className="flex-1">{category.name}</span>
                                                    {category.asset_count > 0 && <span className="text-xs opacity-80">{category.asset_count}</span>}
                                                </button>
                                            )
                                        })}
                                        {can_view_trash && (trash_count > 0 || lifecycle === 'deleted') && (
                                            <>
                                                <div className="my-1.5 border-t" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.12)' }} />
                                                <button
                                                    onClick={() => { router.get('/app/assets', { lifecycle: 'deleted' }); setMobileCategoriesOpen(false) }}
                                                    className="flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left"
                                                    style={{ backgroundColor: lifecycle === 'deleted' ? activeBgColor : 'transparent', color: lifecycle === 'deleted' ? activeTextColor : textColor }}
                                                >
                                                    <TrashIcon className="mr-3 h-5 w-5 opacity-80" style={{ color: lifecycle === 'deleted' ? activeTextColor : textColor }} />
                                                    <span className="flex-1">Trash</span>
                                                    {trash_count > 0 && <span className="text-xs opacity-80">{trash_count}</span>}
                                                </button>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </>
                )}

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative flex flex-col">
                    {/* Mobile: Categories trigger (below nav, only when sidebar is hidden) */}
                    <div className="lg:hidden flex items-center justify-start gap-2 py-2 px-4 sm:px-6 border-b border-gray-200 bg-white/95 backdrop-blur-sm shrink-0 sticky top-0 z-20">
                        <button
                            type="button"
                            onClick={() => setMobileCategoriesOpen(true)}
                            className="inline-flex max-w-full items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                        >
                            <svg className="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                            <span>Categories</span>
                            {selectedCategoryId != null && categories?.find(c => c.id === selectedCategoryId) && (
                                <span className="text-gray-500 truncate max-w-[140px]">— {categories.find(c => c.id === selectedCategoryId).name}</span>
                            )}
                        </button>
                    </div>
                    <div 
                        className={`flex-1 min-h-0 overflow-y-auto transition-[padding-right] duration-300 ease-in-out relative pb-0 ${lifecycle === 'deleted' ? 'bg-gray-100' : ''}`}
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
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                        {/* Brief feedback when an asset can't be added to the download bucket (e.g. not published) */}
                        {/* Asset Grid Toolbar - Always visible (persists across categories) */}
                        {/* Primary metadata filters are now integrated into the toolbar (between search and controls) */}
                        <div className="mb-8">
                            <AssetGridToolbar
                                showInfo={showInfo}
                                onToggleInfo={() => setShowInfo(v => !v)}
                                cardSize={cardSize}
                                onCardSizeChange={setCardSize}
                                primaryColor={workspaceAccentColor}
                                selectedCount={selectedCount}
                                filterable_schema={filterable_schema}
                                selectedCategoryId={selectedCategoryId}
                                available_values={availableValues}
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
                                        available_values={availableValues}
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
                                            router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'next_page_url', 'sort', 'sort_direction'] })
                                        }}
                                        assetResultCount={assetsList?.length ?? 0}
                                        totalInCategory={assetsList?.length ?? 0}
                                        hasMoreAvailable={!!nextPageUrl}
                                    />
                                }
                            />
                        </div>
                            
                            {/* Assets Grid or Empty State */}
                            {assetsList && assetsList.length > 0 ? (
                                <>
                                <AssetGrid 
                                    assets={safeAssetsList} 
                                    onAssetClick={handleAssetClick}
                                    cardSize={cardSize}
                                    cardStyle={cardStyle}
                                    showInfo={showInfo}
                                    selectedAssetId={activeAssetId}
                                    primaryColor={workspaceAccentColor}
                                    isPendingApprovalMode={isPendingApprovalMode}
                                    isPendingPublicationFilter={isPendingPublicationFilter}
                                    onAssetApproved={(assetId) => {
                                        // Remove approved asset from local state
                                        setAssetsList((prev) => prev.filter(a => a.id !== assetId))
                                    }}
                                />
                                {/* Only mount sentinel when there is a next page — avoids observer firing when assets < page size */}
                                {nextPageUrl ? <div ref={loadMoreRef} className="h-10" aria-hidden="true" /> : null}
                                {loading && (
                                    <div className="flex justify-center py-6">
                                        <svg className="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                        </svg>
                                    </div>
                                )}
                                {nextPageUrl && (
                                    <LoadMoreFooter onLoadMore={loadMore} hasMore={!!nextPageUrl} isLoading={loading} />
                                )}
                                </>
                            ) : (
                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                <div className="mb-8">
                                    <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                                </div>
                                <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                    {isPendingPublicationFilter
                                        ? (auth?.user?.brand_role === 'contributor' && !['admin', 'owner'].includes(auth?.user?.tenant_role?.toLowerCase() || ''))
                                            ? "You don't have any assets awaiting review right now."
                                            : 'No assets are currently awaiting review.'
                                        : searchQuery?.trim()
                                            ? 'No results for this search'
                                            : selectedCategoryId
                                                ? 'No assets in this category yet'
                                                : 'No assets yet'}
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    {isPendingPublicationFilter
                                        ? 'Assets that require approval will appear here once submitted.'
                                        : searchQuery?.trim()
                                            ? 'Try different keywords or clear the search to see all assets.'
                                            : selectedCategoryId
                                                ? 'Get started by uploading your first asset to this category. Organize your brand assets and keep everything in one place.'
                                                : 'Get started by selecting a category or uploading your first asset. Organize your brand assets and keep everything in sync.'}
                                </p>
                                {!isPendingPublicationFilter && (
                                    <div className="mt-8">
                                        <AddAssetButton 
                                            defaultAssetType="asset" 
                                            onClick={handleOpenUploadDialog}
                                            disabled={isAutoClosing}
                                        />
                                    </div>
                                )}
                            </div>
                        )}
                        </div>
                    </div>

                    {/* Asset Drawer - Desktop (pushes grid) */}
                    {/* CRITICAL: Drawer identity is based ONLY on activeAssetId */}
                    {/* Drawer must tolerate temporary undefined asset object during async updates */}
                    {/* Only render drawer if activeAssetId is set - asset object may be temporarily undefined */}
                    {activeAssetId && (
                        <div className="hidden md:block absolute right-0 top-0 bottom-0 z-50">
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
                                selectionAssetType="asset"
                                primaryColor={workspaceAccentColor}
                            />
                        </div>
                    )}
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
                            primaryColor={workspaceAccentColor}
                        />
                    </div>
                )}
            </div>
            
            {/* Phase 2 – Step 7: Bulk Metadata Edit Modal */}
            {/* Phase 4: Unified Selection ActionBar */}
            <SelectionActionBar
                currentPageIds={safeAssetsList.map((a) => a.id)}
                currentPageItems={safeAssetsList.map((a) => ({
                    id: a.id,
                    type: 'asset',
                    name: a.title ?? a.original_filename ?? '',
                    thumbnail_url: a.final_thumbnail_url ?? a.thumbnail_url ?? a.preview_thumbnail_url ?? null,
                    category_id: a.metadata?.category_id ?? a.category_id ?? null,
                }))}
                onOpenBulkMetadataAdd={(ids) => {
                    setBulkSelectedAssetIds(ids)
                    setShowBulkMetadataModal(true)
                }}
                onOpenBulkEdit={(ids) => {
                    setBulkSelectedAssetIds(ids)
                    setShowBulkActionsModal(true)
                }}
            />

            {showBulkActionsModal && bulkSelectedAssetIds.length > 0 && (
                <BulkActionsModal
                    assetIds={bulkSelectedAssetIds}
                    selectionSummary={computeSelectionSummary(safeAssetsList, bulkSelectedAssetIds)}
                    isTrashMode={lifecycle === 'deleted'}
                    canForceDelete={auth?.user?.tenant_role === 'owner' || auth?.user?.tenant_role === 'admin' || auth?.tenant_role === 'owner' || auth?.tenant_role === 'admin'}
                    onClose={() => setShowBulkActionsModal(false)}
                    onComplete={(result) => {
                        router.reload({ only: ['assets', 'next_page_url'] })
                        // Clear selection when items leave the view (trash or force delete)
                        if (result?.actionId === 'SOFT_DELETE' || result?.actionId === 'FORCE_DELETE') {
                            setBulkSelectedAssetIds([])
                            setIsBulkMode(false)
                            clearSelection()
                        }
                    }}
                    onOpenMetadataEdit={(ids) => {
                        setShowBulkActionsModal(false)
                        setBulkSelectedAssetIds(ids)
                        setShowBulkMetadataModal(true)
                    }}
                />
            )}
            {showBulkMetadataModal && bulkSelectedAssetIds.length > 0 && (
                <BulkMetadataEditModal
                    assetIds={bulkSelectedAssetIds}
                    onClose={() => setShowBulkMetadataModal(false)}
                    onComplete={() => {
                        router.reload({ only: ['assets', 'next_page_url'] })
                        setBulkSelectedAssetIds([])
                        setIsBulkMode(false)
                        clearSelection()
                        setShowBulkMetadataModal(false)
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

            {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on category change */}
        </div>
    )
}
