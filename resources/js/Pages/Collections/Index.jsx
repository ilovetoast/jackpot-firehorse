/**
 * Collections Index (C4 read-only UI; C5 create + add/remove assets).
 * Uses CollectionAssetQueryService for asset data; C5 adds create and assign UI.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { usePage, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import CollectionsSidebar from '../../Components/Collections/CollectionsSidebar'
import CollectionPublicBar from '../../Components/Collections/CollectionPublicBar'
import CreateCollectionModal from '../../Components/Collections/CreateCollectionModal'
import EditCollectionModal from '../../Components/Collections/EditCollectionModal'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import BulkMetadataEditModal from '../../Components/BulkMetadataEditModal'
import SelectionActionBar from '../../Components/SelectionActionBar'
import { useSelection } from '../../contexts/SelectionContext'
import { RectangleStackIcon, PlusIcon, FolderIcon } from '@heroicons/react/24/outline'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import { getWorkspaceButtonColor, hexToRgba, getContrastTextColor } from '../../utils/colorUtils'
import axios from 'axios'

export default function CollectionsIndex({
    collections = [],
    assets = [],
    next_page_url = null,
    selected_collection = null,
    can_update_collection = false,
    can_create_collection = false,
    can_add_to_collection = false,
    can_remove_from_collection = false,
    public_collections_enabled = false,
    sort = 'created',
    sort_direction = 'desc',
    q: searchQuery = '',
}) {
    const { auth } = usePage().props
    const selectedCollectionId = selected_collection?.id ?? null

    const sidebarColor = auth.activeBrand?.nav_color || auth.activeBrand?.primary_color || '#1f2937'
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
    // Use full accent color for selected collection; hover uses subtle tint
    const activeBgColor = workspaceAccentColor
    const activeTextColor = getContrastTextColor(workspaceAccentColor)
    const hoverBgColor = hexToRgba(workspaceAccentColor, 0.12)

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
    const [mobileContentTranslateX, setMobileContentTranslateX] = useState(0)
    const [mobileContentAnimating, setMobileContentAnimating] = useState(false)
    const mobileContentAnimationTimeoutRef = useRef(null)
    const mobileTouchStartRef = useRef(null)
    const mobileTouchDeltaRef = useRef({ x: 0, y: 0 })
    const [activeAssetId, setActiveAssetId] = useState(null)
    const [showBulkEditModal, setShowBulkEditModal] = useState(false)
    const [bulkSelectedAssetIds, setBulkSelectedAssetIds] = useState([])
    const safeAssetsList = (assetsList || []).filter(Boolean)
    const activeAsset = activeAssetId ? safeAssetsList.find((a) => a?.id === activeAssetId) : null

    const navigateToCollection = useCallback((collectionId, options = {}) => {
        const params = collectionId == null ? {} : { collection: collectionId }
        router.get('/app/collections', params, {
            preserveState: true,
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
    }, [selectedCollectionId])

    const { selectedCount, clearSelection, getSelectedOnPage } = useSelection()

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
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridCardSize', cardSize.toString())
    }, [cardSize])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridShowInfo', showInfo.toString())
    }, [showInfo])

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
            <AppNav brand={auth.activeBrand} tenant={null} />

            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <CollectionsSidebar
                        collections={collections}
                        selectedCollectionId={selectedCollectionId}
                        sidebarColor={sidebarColor}
                        textColor={textColor}
                        activeBgColor={activeBgColor}
                        activeTextColor={activeTextColor}
                        hoverBgColor={hoverBgColor}
                        canCreateCollection={can_create_collection}
                        onCreateCollection={() => setShowCreateModal(true)}
                        publicCollectionsEnabled={public_collections_enabled}
                    />
                </div>

                <CreateCollectionModal
                    open={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    onCreated={handleCollectionCreated}
                />
                <EditCollectionModal
                    open={showEditModal}
                    collection={selected_collection}
                    publicCollectionsEnabled={public_collections_enabled}
                    onClose={() => setShowEditModal(false)}
                    onSaved={() => {
                        setShowEditModal(false)
                        router.reload()
                    }}
                />

                {/* Main content */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative flex flex-col">
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
                            {!showGrid ? (
                                /* No collection selected: show "No collections yet" or "Select a collection" */
                                collections.length === 0 ? (
                                    <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                        <div className="mb-8">
                                            <RectangleStackIcon className="mx-auto h-16 w-16 text-gray-300" />
                                        </div>
                                        <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                            No collections yet
                                        </h2>
                                        <p className="mt-4 text-base leading-7 text-gray-600">
                                            Collections let you group and share assets. No collections have been created yet.
                                        </p>
                                    </div>
                                ) : (
                                    /* Hulu-style collection cards grid: dark background, photography from collection assets */
                                    <div className="min-h-[60vh]">
                                        <div className="mb-8">
                                            <h2 className="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
                                                Your collections
                                            </h2>
                                            <p className="mt-2 text-base text-gray-600">
                                                Choose a collection to view its assets.
                                            </p>
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                            {collections.map((c) => {
                                                const brandGradient = `linear-gradient(to top, ${hexToRgba(workspaceAccentColor, 0.95)}, ${hexToRgba(workspaceAccentColor, 0.5)}, transparent)`
                                                const placeholderGradient = `linear-gradient(145deg, ${workspaceAccentColor} 0%, #0f172a 100%)`
                                                const overlayTextColor = getContrastTextColor(workspaceAccentColor)
                                                return (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onClick={() => navigateToCollection(c.id)}
                                                    className="group relative overflow-hidden rounded-xl shadow-lg ring-1 transition-all duration-300 hover:ring-2 hover:ring-offset-2 hover:ring-offset-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-50"
                                                    style={{
                                                        '--tw-ring-color': workspaceAccentColor,
                                                        backgroundColor: workspaceAccentColor,
                                                    }}
                                                >
                                                    <div className="aspect-[4/3] w-full overflow-hidden">
                                                        {c.featured_image_url ? (
                                                            <img
                                                                src={c.featured_image_url}
                                                                alt=""
                                                                className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                            />
                                                        ) : (
                                                            <div
                                                                className="flex h-full w-full items-center justify-center"
                                                                style={{ background: placeholderGradient }}
                                                            >
                                                                <RectangleStackIcon className="h-16 w-16" style={{ color: overlayTextColor, opacity: 0.9 }} />
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div
                                                        className="absolute inset-x-0 bottom-0 pt-16 pb-4 px-4"
                                                        style={{ background: brandGradient }}
                                                    >
                                                        <h3 className="text-lg font-semibold truncate" style={{ color: overlayTextColor }}>
                                                            {c.name}
                                                        </h3>
                                                        <p className="mt-0.5 text-sm" style={{ color: overlayTextColor, opacity: 0.85 }}>
                                                            {typeof c.assets_count === 'number'
                                                                ? `${c.assets_count} asset${c.assets_count !== 1 ? 's' : ''}`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                </button>
                                            )})}
                                        </div>
                                    </div>
                                )
                            ) : (
                                <>
                                    {/* C10: Collection bar: name + Public toggle (only when feature enabled) */}
                                    {selected_collection && (
                                        <CollectionPublicBar
                                            collection={selected_collection}
                                            publicCollectionsEnabled={public_collections_enabled}
                                            assetCount={assetsList.length}
                                            canUpdateCollection={can_update_collection}
                                            onEditClick={() => setShowEditModal(true)}
                                            onPublicChange={() => {
                                                router.reload()
                                            }}
                                            primaryColor={auth?.activeBrand?.primary_color}
                                        />
                                    )}
                                    <div className="mb-8">
                                        <AssetGridToolbar
                                            showInfo={showInfo}
                                            onToggleInfo={() => setShowInfo((v) => !v)}
                                            cardSize={cardSize}
                                            onCardSizeChange={setCardSize}
                                            primaryColor={workspaceAccentColor}
                                            selectedCount={selectedCount}
                                            filterable_schema={[]}
                                            selectedCategoryId={null}
                                            available_values={{}}
                                            sortBy={sort}
                                            sortDirection={sort_direction}
                                            onSortChange={(newSort, newDir) => {
                                                const urlParams = new URLSearchParams(window.location.search)
                                                urlParams.set('sort', newSort)
                                                urlParams.set('sort_direction', newDir)
                                                router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'sort', 'sort_direction'] })
                                            }}
                                            showMoreFilters={true}
                                            moreFiltersContent={
                                                <AssetGridSecondaryFilters
                                                    filterable_schema={[]}
                                                    selectedCategoryId={null}
                                                    available_values={{}}
                                                    canManageFields={false}
                                                    assetType="image"
                                                    primaryColor={workspaceAccentColor}
                                                    sortBy={sort}
                                                    sortDirection={sort_direction}
                                                    onSortChange={(newSort, newDir) => {
                                                        const urlParams = new URLSearchParams(window.location.search)
                                                        urlParams.set('sort', newSort)
                                                        urlParams.set('sort_direction', newDir)
                                                        router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'sort', 'sort_direction'] })
                                                    }}
                                                    assetResultCount={assetsList?.length ?? 0}
                                                    totalInCategory={assetsList?.length ?? 0}
                                                    hasMoreAvailable={!!nextPageUrl}
                                                />
                                            }
                                        />
                                    </div>

                                    {hasAssets ? (
                                        <>
                                        <AssetGrid
                                            assets={safeAssetsList}
                                            onAssetClick={(asset) => setActiveAssetId(asset?.id ?? null)}
                                            cardSize={cardSize}
                                            cardStyle={(auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'}
                                            showInfo={showInfo}
                                            selectedAssetId={activeAssetId}
                                            primaryColor={workspaceAccentColor}
                                            selectedAssetIds={[]}
                                            onAssetSelect={null}
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
                                        /* Empty state: collection selected but no assets */
                                        <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                            <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                                            <h2 className="mt-4 text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                                This collection doesn&apos;t contain any assets yet.
                                            </h2>
                                            <p className="mt-4 text-base leading-7 text-gray-600">
                                                Assets added to this collection will appear here.
                                            </p>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on collection change */}

                    {/* Asset Drawer - Desktop */}
                    {activeAssetId && (
                        <div className="hidden md:block absolute right-0 top-0 bottom-0 z-50">
                            <AssetDrawer
                                key={activeAssetId}
                                asset={activeAsset}
                                onClose={() => setActiveAssetId(null)}
                                assets={safeAssetsList}
                                currentAssetIndex={activeAsset ? safeAssetsList.findIndex((a) => a?.id === activeAsset?.id) : -1}
                                onAssetUpdate={handleLifecycleUpdate}
                                selectionAssetType="asset"
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
                        </div>
                    )}
                </div>
            </div>

            {/* Asset Drawer - Mobile */}
            {activeAssetId && (
                <div className="md:hidden fixed inset-0 z-50">
                    <div className="absolute inset-0 bg-black/50" onClick={() => setActiveAssetId(null)} aria-hidden="true" />
                    <AssetDrawer
                        key={activeAssetId}
                        asset={activeAsset}
                        onClose={() => setActiveAssetId(null)}
                        assets={safeAssetsList}
                        currentAssetIndex={activeAsset ? safeAssetsList.findIndex((a) => a?.id === activeAsset?.id) : -1}
                        onAssetUpdate={handleLifecycleUpdate}
                        bucketAssetIds={bucketAssetIds}
                        onBucketToggle={handleBucketToggle}
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
                </div>
            )}

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
                    setShowBulkEditModal(true)
                }}
            />

            {showBulkEditModal && bulkSelectedAssetIds.length > 0 && (
                <BulkMetadataEditModal
                    assetIds={bulkSelectedAssetIds}
                    onClose={() => setShowBulkEditModal(false)}
                    onComplete={() => {
                        router.reload({ only: ['assets', 'next_page_url'] })
                        setBulkSelectedAssetIds([])
                        clearSelection()
                    }}
                />
            )}
        </div>
    )
}
