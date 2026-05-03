/**
 * Collections Index (C4 read-only UI; C5 create + add/remove assets).
 * Uses CollectionAssetQueryService for asset data; C5 adds create and assign UI.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { usePage, router } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import CollectionsSidebar from '../../Components/Collections/CollectionsSidebar'
import CollectionPublicBar from '../../Components/Collections/CollectionPublicBar'
import CampaignIdentityBanner from '../../Components/Collections/CampaignIdentityBanner'
import CreateCollectionModal from '../../Components/Collections/CreateCollectionModal'
import EditCollectionModal from '../../Components/Collections/EditCollectionModal'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkActionsModal, { computeSelectionSummary } from '../../Components/BulkActionsModal'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import SelectionActionBar from '../../Components/SelectionActionBar'
import { useSelection } from '../../contexts/SelectionContext'
import { useBucketOptional } from '../../contexts/BucketContext'
import { RectangleStackIcon, PlusIcon, FolderIcon } from '@heroicons/react/24/outline'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import InfiniteScrollGridSpinner from '../../Components/InfiniteScrollGridSpinner'
import CollectionFiltersBar from '../../Components/Collections/CollectionFiltersBar'
import {
    getWorkspaceButtonColor,
    getWorkspaceContextualTone,
    getLuminance,
    hexToRgba,
    getContrastTextColor,
    resolveWorkspaceSidebarSurface,
} from '../../utils/colorUtils'
import axios from 'axios'

const COLLECTIONS_PARTIAL_RELOAD = [
    'assets',
    'next_page_url',
    'filtered_grid_total',
    'grid_folder_total',
    'q',
    'sort',
    'sort_direction',
    'group_by_category',
    'collection_type',
    'category_id',
    'filter_categories',
    'filterable_schema',
    'available_values',
    'filters',
    /** Keep current collection when using `only` — otherwise Inertia can clear selection. */
    'selected_collection',
]

/** Collections URL params — not metadata filters (avoid collision with `collection` in SPECIAL_FILTER_KEYS). */
const COLLECTIONS_FILTER_URL_NAV_KEYS = ['collection', 'collection_type', 'category_id']


export default function CollectionsIndex({
    collections = [],
    assets = [],
    next_page_url = null,
    filtered_grid_total = 0,
    grid_folder_total = 0,
    selected_collection = null,
    can_update_collection = false,
    can_create_collection = false,
    can_add_to_collection = false,
    can_remove_from_collection = false,
    public_collections_enabled = false,
    billing_upgrade_url = '/app/billing',
    sort = 'created',
    sort_direction = 'desc',
    q: searchQuery = '',
    collection_type = 'all',
    category_id: categoryFilterId = null,
    group_by_category = false,
    filter_categories = [],
    filterable_schema = [],
    available_values: availableValuesProp = {},
}) {
    const page = usePage()
    const { auth } = page.props
    const availableValues = availableValuesProp || page.props.available_values || {}
    const selectedCollectionId = selected_collection?.id ?? null

    const { isCinematic: sidebarIsCinematic, sidebarColor, backdropCss: sidebarBackdropCss } =
        resolveWorkspaceSidebarSurface(auth.activeBrand)
    const workspaceAccentColor = getWorkspaceButtonColor(auth.activeBrand)
    const accentLum = getLuminance(workspaceAccentColor)
    const isLightColor = (color) => {
        if (!color || color === '#ffffff' || color === '#FFFFFF') return true
        const hex = color.replace('#', '')
        const r = parseInt(hex.substr(0, 2), 16)
        const g = parseInt(hex.substr(2, 2), 16)
        const b = parseInt(hex.substr(4, 2), 16)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
        return luminance > 0.5
    }
    const textColor = sidebarIsCinematic || !isLightColor(sidebarColor) ? '#ffffff' : '#000000'
    // Full brand tint for normal accents; neutral chrome when style is white/black so selection stays visible
    const activeBgColor =
        accentLum < 0.06 || accentLum > 0.94
            ? getWorkspaceContextualTone(workspaceAccentColor)
            : workspaceAccentColor
    const activeTextColor = getContrastTextColor(activeBgColor)
    const hoverBgColor = hexToRgba(workspaceAccentColor, 0.12)

    const brandPrimaryHex = auth?.activeBrand?.primary_color || '#6366f1'

    const [assetsList, setAssetsList] = useState(Array.isArray(assets) ? assets.filter(Boolean) : [])
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loading, setLoading] = useState(false)
    const loadMoreRef = useRef(null)

    useEffect(() => {
        setAssetsList(Array.isArray(assets) ? assets.filter(Boolean) : [])
        setNextPageUrl(next_page_url ?? null)
    }, [assets, next_page_url])

    const loadMore = useCallback(async () => {
        if (!nextPageUrl || loading) return
        setLoading(true)
        try {
            const url = nextPageUrl + (nextPageUrl.includes('?') ? '&' : '?') + 'load_more=1'
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

    useEffect(() => {
        return () => {
            if (mobileContentAnimationTimeoutRef.current) {
                clearTimeout(mobileContentAnimationTimeoutRef.current)
            }
        }
    }, [])

    const [showCreateModal, setShowCreateModal] = useState(false)
    const [showEditModal, setShowEditModal] = useState(false)
    const [editModalFocusShareLink, setEditModalFocusShareLink] = useState(false)

    const consumeShareLinkFocus = useCallback(() => setEditModalFocusShareLink(false), [])
    const openEditToShareLink = useCallback(() => {
        setEditModalFocusShareLink(true)
        setShowEditModal(true)
    }, [])
    const [mobileContentTranslateX, setMobileContentTranslateX] = useState(0)
    const [mobileContentAnimating, setMobileContentAnimating] = useState(false)
    const mobileContentAnimationTimeoutRef = useRef(null)
    const mobileTouchStartRef = useRef(null)
    const mobileTouchDeltaRef = useRef({ x: 0, y: 0 })
    const [activeAssetId, setActiveAssetId] = useState(null)
    const [openDrawerWithZoom, setOpenDrawerWithZoom] = useState(false)
    const [showBulkActionsModal, setShowBulkActionsModal] = useState(false)
    const [showBulkMetadataModal, setShowBulkMetadataModal] = useState(false)
    const [bulkMetadataInitialOp, setBulkMetadataInitialOp] = useState(null)
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])
    const safeAssetsList = (assetsList || []).filter(Boolean)
    const activeAsset = activeAssetId ? safeAssetsList.find((a) => a?.id === activeAssetId) : null

    const categorySections = useMemo(() => {
        if (!group_by_category || !safeAssetsList.length) return null
        const map = new Map()
        for (const a of safeAssetsList) {
            const key = a.category?.id != null ? String(a.category.id) : 'uncategorized'
            const label = a.category?.name ?? 'Uncategorized'
            if (!map.has(key)) {
                map.set(key, { key, label, assets: [] })
            }
            map.get(key).assets.push(a)
        }
        const sections = Array.from(map.values())
        sections.sort((x, y) => {
            const ux = x.key === 'uncategorized' ? 1 : 0
            const uy = y.key === 'uncategorized' ? 1 : 0
            if (ux !== uy) return ux - uy
            return x.label.localeCompare(y.label, undefined, { sensitivity: 'base' })
        })
        return sections.length > 0 ? sections : null
    }, [group_by_category, safeAssetsList])

    const navigateToCollection = useCallback((collectionId, options = {}) => {
        const params = collectionId == null ? {} : { collection: collectionId }
        router.get('/app/collections', params, {
            /* Same Inertia page component: preserveState kept stale props (selected_collection stayed null). */
            preserveState: false,
            preserveScroll: true,
            ...options,
        })
    }, [])

    const handleCollectionCreated = (newCollection) => {
        navigateToCollection(newCollection.id, { preserveState: false, preserveScroll: false })
    }

    useEffect(() => {
        if (activeAssetId && !safeAssetsList.some((a) => a?.id === activeAssetId)) {
            setActiveAssetId(null)
        }
    }, [activeAssetId, safeAssetsList])

    useEffect(() => {
        setActiveAssetId(null)
        setOpenDrawerWithZoom(false)
    }, [selectedCollectionId])

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
        setActiveAssetId(asset?.id ?? null)
    }, [])
    const handleAssetDoubleClick = useCallback((asset) => {
        setOpenDrawerWithZoom(true)
        setActiveAssetId(asset?.id ?? null)
    }, [])
    const handleInitialZoomConsumed = useCallback(() => {
        setOpenDrawerWithZoom(false)
    }, [])

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
    const getStoredGridLayout = () => {
        if (typeof window === 'undefined') return 'grid'
        const v = localStorage.getItem('assetGridLayout')
        return v === 'masonry' ? 'masonry' : 'grid'
    }
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    const [layoutMode, setLayoutMode] = useState(getStoredGridLayout)
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridCardSize', cardSize.toString())
    }, [cardSize])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridShowInfo', showInfo.toString())
    }, [showInfo])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridLayout', layoutMode)
    }, [layoutMode])

    const handleLifecycleUpdate = (updatedAsset) => {
        setAssetsList((prev) =>
            (prev || []).filter(Boolean).map((a) => (a?.id === updatedAsset?.id ? { ...a, ...updatedAsset } : a))
        )
    }

    const showGrid = selectedCollectionId != null
    const hasAssets = assetsList && assetsList.length > 0

    const mobileCollectionTabs = useMemo(() => {
        const tabs = [{
            key: 'overview',
            label: 'Overview',
            collectionId: null,
            count: collections.length > 0 ? collections.length : null,
        }]

        collections.forEach((collection) => {
            tabs.push({
                key: String(collection.id),
                label: collection.name,
                collectionId: collection.id,
                count: typeof collection.assets_count === 'number' && collection.assets_count > 0
                    ? collection.assets_count
                    : null,
            })
        })

        return tabs
    }, [collections])

    const activeMobileCollectionTabIndex = useMemo(() => (
        mobileCollectionTabs.findIndex((tab) => {
            if (tab.collectionId == null && selectedCollectionId == null) {
                return true
            }

            if (tab.collectionId == null || selectedCollectionId == null) {
                return false
            }

            return String(tab.collectionId) === String(selectedCollectionId)
        })
    ), [mobileCollectionTabs, selectedCollectionId])

    const safeActiveMobileCollectionTabIndex = activeMobileCollectionTabIndex >= 0
        ? activeMobileCollectionTabIndex
        : (mobileCollectionTabs.length > 0 ? 0 : -1)

    const animateMobileContentSwipe = useCallback((direction) => {
        if (!direction) {
            return
        }

        if (mobileContentAnimationTimeoutRef.current) {
            clearTimeout(mobileContentAnimationTimeoutRef.current)
        }

        setMobileContentAnimating(false)
        setMobileContentTranslateX(direction > 0 ? 32 : -32)

        if (typeof window !== 'undefined') {
            window.requestAnimationFrame(() => {
                setMobileContentAnimating(true)
                setMobileContentTranslateX(0)
            })
        }

        mobileContentAnimationTimeoutRef.current = setTimeout(() => {
            setMobileContentAnimating(false)
        }, 220)
    }, [])

    const handleMobileCollectionTabChange = useCallback((targetIndex) => {
        if (targetIndex < 0 || targetIndex >= mobileCollectionTabs.length) {
            return
        }

        const currentIndex = activeMobileCollectionTabIndex
        if (currentIndex >= 0 && currentIndex === targetIndex) {
            return
        }

        const fallbackIndex = safeActiveMobileCollectionTabIndex
        const baseIndex = currentIndex >= 0 ? currentIndex : fallbackIndex
        const direction = targetIndex > baseIndex ? 1 : -1
        animateMobileContentSwipe(direction)

        const targetTab = mobileCollectionTabs[targetIndex]
        if (!targetTab) {
            return
        }

        navigateToCollection(targetTab.collectionId)
    }, [mobileCollectionTabs, activeMobileCollectionTabIndex, safeActiveMobileCollectionTabIndex, animateMobileContentSwipe, navigateToCollection])

    const handleMobileContentTouchStart = useCallback((event) => {
        if (mobileCollectionTabs.length <= 1) {
            return
        }

        const touch = event.changedTouches?.[0]
        if (!touch) {
            return
        }

        mobileTouchStartRef.current = {
            x: touch.clientX,
            y: touch.clientY,
        }
        mobileTouchDeltaRef.current = { x: 0, y: 0 }
    }, [mobileCollectionTabs.length])

    const handleMobileContentTouchMove = useCallback((event) => {
        if (!mobileTouchStartRef.current) {
            return
        }

        const touch = event.changedTouches?.[0]
        if (!touch) {
            return
        }

        mobileTouchDeltaRef.current = {
            x: touch.clientX - mobileTouchStartRef.current.x,
            y: touch.clientY - mobileTouchStartRef.current.y,
        }
    }, [])

    const handleMobileContentTouchEnd = useCallback(() => {
        if (!mobileTouchStartRef.current || mobileCollectionTabs.length <= 1 || safeActiveMobileCollectionTabIndex < 0) {
            mobileTouchStartRef.current = null
            mobileTouchDeltaRef.current = { x: 0, y: 0 }
            return
        }

        const { x, y } = mobileTouchDeltaRef.current
        mobileTouchStartRef.current = null
        mobileTouchDeltaRef.current = { x: 0, y: 0 }

        const isHorizontalSwipe = Math.abs(x) >= 60 && Math.abs(x) > Math.abs(y) * 1.2
        if (!isHorizontalSwipe) {
            return
        }

        const nextIndex = x < 0
            ? safeActiveMobileCollectionTabIndex + 1
            : safeActiveMobileCollectionTabIndex - 1

        handleMobileCollectionTabChange(nextIndex)
    }, [mobileCollectionTabs.length, safeActiveMobileCollectionTabIndex, handleMobileCollectionTabChange])

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppHead title="Collections" />
            <AppNav brand={auth.activeBrand} tenant={null} />

            <div className="relative flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar — always uses brand sidebar styling (same as Assets / Executions) */}
                <div className="relative z-[1] hidden lg:flex lg:flex-shrink-0">
                    <CollectionsSidebar
                        collections={collections}
                        selectedCollectionId={selectedCollectionId}
                        sidebarColor={sidebarColor}
                        sidebarBackdropCss={sidebarBackdropCss}
                        textColor={textColor}
                        activeBgColor={activeBgColor}
                        activeTextColor={activeTextColor}
                        hoverBgColor={hoverBgColor}
                        transparentBackground={false}
                        canCreateCollection={can_create_collection}
                        onCreateCollection={() => setShowCreateModal(true)}
                        publicCollectionsEnabled={public_collections_enabled}
                    />
                </div>

                <CreateCollectionModal
                    open={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    onCreated={handleCollectionCreated}
                    publicCollectionsEnabled={public_collections_enabled}
                    billingUpgradeUrl={billing_upgrade_url}
                />
                <EditCollectionModal
                    open={showEditModal}
                    collection={selected_collection}
                    publicCollectionsEnabled={public_collections_enabled}
                    billingUpgradeUrl={billing_upgrade_url}
                    initialFocusShareLinkSection={editModalFocusShareLink}
                    onShareLinkFocusConsumed={consumeShareLinkFocus}
                    onClose={() => setShowEditModal(false)}
                    onSaved={() => {
                        setShowEditModal(false)
                        router.reload()
                    }}
                />

                {/* Main content */}
                <div className="relative z-[1] flex flex-1 flex-col overflow-hidden h-full motion-reduce:transition-none bg-gray-50">

                    <div className="lg:hidden border-b border-gray-200 bg-white/95 backdrop-blur-sm shrink-0 sticky top-0 z-20">
                        <div className="px-4 sm:px-6 py-2 flex items-center gap-2">
                            <div className="flex-1 flex items-center gap-2 overflow-x-auto pb-0.5">
                                {mobileCollectionTabs.map((tab, index) => {
                                    const isActive = index === safeActiveMobileCollectionTabIndex
                                    return (
                                        <button
                                            key={tab.key}
                                            type="button"
                                            onClick={() => handleMobileCollectionTabChange(index)}
                                            className="inline-flex shrink-0 items-center gap-1 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2"
                                            style={isActive ? {
                                                backgroundColor: activeBgColor,
                                                borderColor: activeBgColor,
                                                color: activeTextColor,
                                            } : {
                                                backgroundColor: '#ffffff',
                                                borderColor: '#d1d5db',
                                                color: '#374151',
                                            }}
                                            aria-pressed={isActive}
                                        >
                                            <span className="truncate max-w-[150px]">{tab.label}</span>
                                            {tab.count ? <span className="text-xs opacity-80">{tab.count}</span> : null}
                                        </button>
                                    )
                                })}
                            </div>
                            {can_create_collection && (
                                <button
                                    type="button"
                                    onClick={() => setShowCreateModal(true)}
                                    className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                    aria-label="Create collection"
                                    title="Create collection"
                                >
                                    <PlusIcon className="h-5 w-5" />
                                </button>
                            )}
                        </div>
                    </div>
                    <div
                        className="flex-1 min-h-0 overflow-y-auto pb-0"
                        style={{ touchAction: 'pan-y' }}
                        onTouchStart={handleMobileContentTouchStart}
                        onTouchMove={handleMobileContentTouchMove}
                        onTouchEnd={handleMobileContentTouchEnd}
                        onTouchCancel={handleMobileContentTouchEnd}
                    >
                        <div
                            className="py-6 px-4 sm:px-6 lg:px-8"
                            style={{
                                transform: `translateX(${mobileContentTranslateX}px)`,
                                transition: mobileContentAnimating ? 'transform 220ms cubic-bezier(0.22, 1, 0.36, 1)' : 'none',
                                willChange: mobileContentAnimating ? 'transform' : 'auto',
                            }}
                        >
                            <AnimatePresence mode="wait">
                                {!showGrid ? (
                                    <motion.div
                                        key="collections-landing"
                                        className="relative z-10"
                                        initial={{ opacity: 0 }}
                                        animate={{ opacity: 1 }}
                                        exit={{ opacity: 0 }}
                                        transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
                                    >
                                        {collections.length === 0 ? (
                                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                                <div className="mb-6">
                                                    <RectangleStackIcon className="mx-auto h-14 w-14 text-gray-300" />
                                                </div>
                                                <h2 className="text-2xl font-bold text-gray-900">
                                                    No collections yet
                                                </h2>
                                                <p className="mt-3 text-sm leading-6 text-gray-500">
                                                    Collections let you group and share assets. Create your first collection to get started.
                                                </p>
                                                {can_create_collection && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowCreateModal(true)}
                                                        className="mt-6 inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                                        style={{ backgroundColor: workspaceAccentColor }}
                                                    >
                                                        <PlusIcon className="h-4 w-4" />
                                                        Create collection
                                                    </button>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="min-h-[60vh]">
                                                <div className="mb-8">
                                                    <h2 className="text-3xl font-bold text-gray-900">
                                                        Your collections
                                                    </h2>
                                                    <p className="mt-2 text-sm text-gray-500">
                                                        Choose a collection to open the grid.
                                                    </p>
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 sm:gap-6">
                                                    {collections.map((c) => (
                                                        <motion.button
                                                            key={c.id}
                                                            type="button"
                                                            onClick={() => navigateToCollection(c.id)}
                                                            whileHover={{ scale: 1.02, y: -2 }}
                                                            whileTap={{ scale: 0.99 }}
                                                            transition={{ type: 'spring', stiffness: 420, damping: 28 }}
                                                            className="group relative overflow-hidden rounded-xl bg-gray-900 text-left shadow-md transition-shadow duration-300 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transform-none motion-reduce:hover:transform-none"
                                                        >
                                                            <div className="aspect-[16/10] w-full overflow-hidden">
                                                                {c.featured_image_url ? (
                                                                    <img
                                                                        src={c.featured_image_url}
                                                                        alt=""
                                                                        className="h-full w-full object-cover opacity-90 transition-transform duration-500 ease-out group-hover:scale-[1.04]"
                                                                    />
                                                                ) : (
                                                                    <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-gray-800 to-gray-900">
                                                                        <RectangleStackIcon className="h-12 w-12 text-white/20" />
                                                                    </div>
                                                                )}
                                                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-gray-900/90 via-gray-900/30 to-transparent" />
                                                            </div>
                                                            <div className="absolute inset-x-0 bottom-0 p-4">
                                                                <div className="flex items-center gap-2">
                                                                    <h3 className="text-sm font-semibold text-white truncate">
                                                                        {c.name}
                                                                    </h3>
                                                                    {c.has_campaign && (
                                                                        <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide ${c.campaign_status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-white/10 text-white/50'}`}>
                                                                            <svg className="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" /></svg>
                                                                            Campaign
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                <p className="mt-0.5 text-xs text-white/60">
                                                                    {typeof c.assets_count === 'number'
                                                                        ? `${c.assets_count} asset${c.assets_count !== 1 ? 's' : ''}`
                                                                        : ''}
                                                                </p>
                                                            </div>
                                                        </motion.button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </motion.div>
                                ) : (
                                    <motion.div
                                        key="collections-grid"
                                        className="relative z-10"
                                        initial={{ opacity: 0 }}
                                        animate={{ opacity: 1 }}
                                        exit={{ opacity: 0 }}
                                        transition={{ duration: 0.32, ease: [0.22, 1, 0.36, 1] }}
                                    >
                                <>
                                    {/* Collection header: combined when campaign exists, standalone bar otherwise */}
                                    {selected_collection && (
                                        selected_collection.campaign_summary ? (
                                            <CampaignIdentityBanner
                                                campaignSummary={selected_collection.campaign_summary}
                                                collectionId={selected_collection.id}
                                                canUpdateCollection={can_update_collection}
                                                collection={selected_collection}
                                                publicCollectionsEnabled={public_collections_enabled}
                                                billingUpgradeUrl={billing_upgrade_url}
                                                onShareLinkConfigure={openEditToShareLink}
                                                assetCount={assetsList.length}
                                                onEditClick={() => setShowEditModal(true)}
                                                onPublicChange={() => { router.reload() }}
                                                primaryColor={auth?.activeBrand?.primary_color}
                                                brandColors={{
                                                    primary: auth?.activeBrand?.primary_color,
                                                    secondary: auth?.activeBrand?.secondary_color,
                                                    accent: auth?.activeBrand?.accent_color,
                                                }}
                                            />
                                        ) : (
                                            <CollectionPublicBar
                                                collection={selected_collection}
                                                publicCollectionsEnabled={public_collections_enabled}
                                                billingUpgradeUrl={billing_upgrade_url}
                                                onShareLinkConfigure={openEditToShareLink}
                                                assetCount={assetsList.length}
                                                canUpdateCollection={can_update_collection}
                                                onEditClick={() => setShowEditModal(true)}
                                                onPublicChange={() => { router.reload() }}
                                                primaryColor={auth?.activeBrand?.primary_color}
                                            />
                                        )
                                    )}
                                    <div className="mb-8">
                                        <AssetGridToolbar
                                            showInfo={showInfo}
                                            onToggleInfo={() => setShowInfo((v) => !v)}
                                            cardSize={cardSize}
                                            onCardSizeChange={setCardSize}
                                            layoutMode={layoutMode}
                                            onLayoutModeChange={setLayoutMode}
                                            primaryColor={workspaceAccentColor}
                                            selectedCount={selectedCount}
                                            filterable_schema={filterable_schema}
                                            selectedCategoryId={categoryFilterId}
                                            available_values={availableValues}
                                            searchTagAutocompleteTenantId={auth?.activeCompany?.id}
                                            searchPlaceholder="Search items, titles, tags…"
                                            clearFiltersCollectionsView={selectedCollectionId != null}
                                            clearFiltersInertiaOnly={COLLECTIONS_PARTIAL_RELOAD}
                                            filterUrlNavigationKeys={COLLECTIONS_FILTER_URL_NAV_KEYS}
                                            beforeSearchSlot={
                                                selectedCollectionId != null ? (
                                                    <CollectionFiltersBar
                                                        collectionId={selectedCollectionId}
                                                        collectionType={collection_type}
                                                        categoryId={categoryFilterId}
                                                        filterCategories={filter_categories}
                                                        primaryColor={workspaceAccentColor}
                                                    />
                                                ) : null
                                            }
                                            sortBy={sort}
                                            sortDirection={sort_direction}
                                            searchQuery={searchQuery}
                                            inertiaSearchOnly={COLLECTIONS_PARTIAL_RELOAD}
                                            onSortChange={
                                                group_by_category
                                                    ? null
                                                    : (newSort, newDir) => {
                                                          const urlParams = new URLSearchParams(window.location.search)
                                                          urlParams.set('sort', newSort)
                                                          urlParams.set('sort_direction', newDir)
                                                          router.get(window.location.pathname, Object.fromEntries(urlParams), {
                                                              preserveState: true,
                                                              preserveScroll: true,
                                                              only: COLLECTIONS_PARTIAL_RELOAD,
                                                          })
                                                      }
                                            }
                                            showMoreFilters={true}
                                            moreFiltersContent={
                                                <AssetGridSecondaryFilters
                                                    filterable_schema={filterable_schema}
                                                    selectedCategoryId={categoryFilterId}
                                                    available_values={availableValues}
                                                    canManageFields={false}
                                                    assetType={collection_type === 'deliverable' ? 'document' : 'image'}
                                                    filterUrlNavigationKeys={COLLECTIONS_FILTER_URL_NAV_KEYS}
                                                    clearFiltersCollectionsView
                                                    inertiaPartialReloadKeys={COLLECTIONS_PARTIAL_RELOAD}
                                                    primaryColor={workspaceAccentColor}
                                                    sortBy={sort}
                                                    sortDirection={sort_direction}
                                                    onSortChange={
                                                        group_by_category
                                                            ? null
                                                            : (newSort, newDir) => {
                                                                  const urlParams = new URLSearchParams(window.location.search)
                                                                  urlParams.set('sort', newSort)
                                                                  urlParams.set('sort_direction', newDir)
                                                                  router.get(window.location.pathname, Object.fromEntries(urlParams), {
                                                                      preserveState: true,
                                                                      preserveScroll: true,
                                                                      only: COLLECTIONS_PARTIAL_RELOAD,
                                                                  })
                                                              }
                                                    }
                                                    assetResultCount={assetsList?.length ?? 0}
                                                    totalInCategory={assetsList?.length ?? 0}
                                                    filteredGridTotal={typeof filtered_grid_total === 'number' ? filtered_grid_total : null}
                                                    gridFolderTotal={typeof grid_folder_total === 'number' ? grid_folder_total : null}
                                                    hasMoreAvailable={!!nextPageUrl}
                                                />
                                            }
                                        />
                                    </div>

                                    {hasAssets ? (
                                        <>
                                        {categorySections ? (
                                            <div className="space-y-10">
                                                {categorySections.map((sec) => (
                                                    <section key={sec.key}>
                                                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-4 px-0.5 border-b border-gray-100 pb-2">
                                                            {sec.label}
                                                        </h3>
                                                        <AssetGrid
                                                            assets={sec.assets}
                                                            onAssetClick={handleAssetGridClick}
                                                            onAssetDoubleClick={handleAssetDoubleClick}
                                                            cardSize={cardSize}
                                                            layoutMode={layoutMode}
                                                            cardStyle={(auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'}
                                                            showInfo={showInfo}
                                                            selectedAssetId={activeAssetId}
                                                            primaryColor={workspaceAccentColor}
                                                            selectedAssetIds={[]}
                                                            onAssetSelect={null}
                                                        />
                                                    </section>
                                                ))}
                                            </div>
                                        ) : (
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
                                            selectedAssetIds={[]}
                                            onAssetSelect={null}
                                        />
                                        )}
                                        {nextPageUrl ? <div ref={loadMoreRef} className="h-10" aria-hidden="true" /> : null}
                                        {loading && <InfiniteScrollGridSpinner brand={auth?.activeBrand} />}
                                        {nextPageUrl && <LoadMoreFooter onLoadMore={loadMore} hasMore={!!nextPageUrl} isLoading={loading} />}
                                        </>
                                    ) : (
                                        /* Empty state: collection selected but no assets */
                                        <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                            <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                                            <h2 className="mt-4 text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                                This collection is empty
                                            </h2>
                                            <p className="mt-4 text-base leading-7 text-gray-600">
                                                Add assets to this collection from the{' '}
                                                <a href="/app/assets" className="font-medium text-indigo-600 hover:text-indigo-500 underline underline-offset-2">
                                                    Assets
                                                </a>{' '}
                                                or{' '}
                                                <a href="/app/executions" className="font-medium text-indigo-600 hover:text-indigo-500 underline underline-offset-2">
                                                    Executions
                                                </a>{' '}
                                                page by selecting items and choosing &ldquo;Add to collection.&rdquo;
                                            </p>
                                        </div>
                                    )}
                                </>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                    </div>

                    {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on collection change */}

                    {/* Asset Drawer — single instance, portals to document.body */}
                    {activeAssetId && (
                        <AssetDrawer
                            key={activeAssetId}
                            asset={activeAsset}
                            onClose={() => {
                                setActiveAssetId(null)
                                setOpenDrawerWithZoom(false)
                            }}
                            assets={safeAssetsList}
                            currentAssetIndex={activeAsset ? safeAssetsList.findIndex((a) => a?.id === activeAsset?.id) : -1}
                            onAssetUpdate={handleLifecycleUpdate}
                            selectionAssetType="asset"
                            bucketAssetIds={bucketAssetIds}
                            onBucketToggle={handleBucketToggle}
                            initialZoomOpen={openDrawerWithZoom}
                            onInitialZoomConsumed={handleInitialZoomConsumed}
                            collectionContext={{
                                show: true,
                                selectedCollectionId,
                                canAddToCollection: can_add_to_collection,
                                canRemoveFromCollection: can_remove_from_collection,
                                canCreateCollection: can_create_collection,
                                onOpenCreateCollection: () => setShowCreateModal(true),
                                onAssetRemovedFromCollection: (assetId, collectionId) => {
                                    if (collectionId === selectedCollectionId) {
                                        setAssetsList((prev) => (prev || []).filter(Boolean).filter((a) => a?.id !== assetId))
                                        setActiveAssetId(null)
                                    }
                                },
                            }}
                        />
                    )}
                </div>
            </div>

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
                onOpenBulkEdit={(ids) => {
                    setBulkSelectedAssetIds(ids)
                    setShowBulkActionsModal(true)
                }}
                createDownloadSource={selectedCollectionId != null ? 'collection' : 'grid'}
                collectionId={selectedCollectionId}
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
                    onClose={() => setShowBulkActionsModal(false)}
                    onComplete={(result) => {
                        router.reload({ only: COLLECTIONS_PARTIAL_RELOAD })
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
                        router.reload({ only: COLLECTIONS_PARTIAL_RELOAD })
                        setBulkSelectedAssetIds([])
                        clearSelection()
                        setShowBulkMetadataModal(false)
                        setBulkMetadataInitialOp(null)
                    }}
                />
            )}
        </div>
    )
}
